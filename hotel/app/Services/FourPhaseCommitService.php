<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Log;

class FourPhaseCommitService
{
    protected $nodes;

    public function __construct()
    {
        // 1. DÁN CỨNG (HARDCODE) 5 LINK RENDER
        // Vượt qua 100% lỗi bộ nhớ đệm (Cache) của biến env() trên Docker
        $this->nodes = [
            'https://node-1-khanh.onrender.com',
            'https://node-2-khai.onrender.com',
            'https://node-3-khaiii.onrender.com',
            'https://node-4-kien.onrender.com',
            'https://node-5-duy.onrender.com'
        ];
    }

    public function executeTransaction(array $bookingData): bool
    {
        $transactionId = $bookingData['id'];
        $roomId = $bookingData['room_id'] ?? null;
        $customerName = $bookingData['name'] ?? null;

        try {
            if (!$this->phase1CanCommit($bookingData)) {
                $this->abortTransaction($transactionId, "TỪ CHỐI (PHA 1)", $roomId, $customerName);
                return false;
            }

            if (!$this->phase2PreCommit($transactionId, $roomId, $customerName)) {
                $this->abortTransaction($transactionId, "TỪ CHỐI (PHA 2)", $roomId, $customerName);
                return false;
            }

            // Tạm dừng để Demo 4 Pha
            sleep(10); 

            if (!$this->phase3DoCommit($transactionId)) {
                $this->abortTransaction($transactionId, "LỖI CHỐT HẠ", $roomId, $customerName);
                return false;
            }

            return true; 

        } catch (\Exception $e) {
            $errorMsg = $e->getMessage();
            
            // 2. BẮT LỖI NODE_DEAD PHIÊN BẢN CLOUD (Đọc tên miền thay vì Đọc cổng)
            if (strpos($errorMsg, 'NODE_DEAD|') !== false) {
                $parts = explode('|', $errorMsg);
                $host = $parts[1] ?? 'Unknown_Node';
                $detail = $parts[2] ?? 'Không rõ lý do';
                
                $reason = "HỦY DO NODE [$host] TỪ CHỐI"; 
                
                // Bắn lệnh Hủy kèm theo Lý do sang cho các Node đang sống
                $this->abortTransaction($transactionId, $reason, $roomId, $customerName);
                
                // Đá văng trang chủ với thông báo lỗi chuẩn xác: Ai chết và Vì sao chết
                throw new \Exception("Chi tiết lỗi mạng: $host | Nguyên nhân: $detail");
            } 
            else {
                // Nếu bị lỗi thật sự do cướp phòng
                $this->abortTransaction($transactionId, "ABORTED (BỊ CƯỚP PHÒNG)", $roomId, $customerName);
                throw $e; 
            }
        }
    }

    private function phase1CanCommit($data): bool
    {
        return $this->sendParallelRequests('/api/can-commit', ['id' => $data['id'], 'room_id' => $data['room_id']], 'YES');
    }

    private function phase2PreCommit($transactionId, $roomId = null, $customerName = null): bool
    {
        return $this->sendParallelRequests('/api/pre-commit', ['transaction_id' => $transactionId, 'room_id' => $roomId, 'customer_name' => $customerName], 'ACK');
    }

    private function phase3DoCommit($transactionId): bool
    {
        return $this->sendParallelRequests('/api/do-commit', ['transaction_id' => $transactionId], 'SUCCESS');
    }

    public function abortTransaction($transactionId, $reason = "ABORTED", $roomId = null, $customerName = null)
    {
        if (empty($this->nodes)) return;
        
        foreach ($this->nodes as $nodeUrl) {
            try {
                // Thêm withoutVerifying() để ép vượt rào bảo mật SSL trên Cloud
                Http::withoutVerifying()->timeout(3)->post($nodeUrl . '/api/abort', [
                    'transaction_id' => $transactionId,
                    'reason' => $reason,
                    'room_id' => $roomId,
                    'customer_name' => $customerName
                ]);
            } catch (\Exception $e) {
                // Thằng nào chết thì kệ nó, lặp tiếp để cứu các thằng đang sống
            }
        }
    }

    private function sendParallelRequests($endpoint, $payload, $expectedStatus): bool
    {
        if (empty($this->nodes)) return true; 

        // Gửi lệnh song song, dùng chính URL làm Alias
        $responses = Http::pool(function (Pool $pool) use ($endpoint, $payload) {
            foreach ($this->nodes as $nodeUrl) {
                // 3. VŨ KHÍ TỐI THƯỢNG: withoutVerifying() để không bị Cloud chặn SSL
                $pool->as($nodeUrl)->withoutVerifying()->timeout(15)->post($nodeUrl . $endpoint, $payload);
            }
        });

        // Duyệt kết quả dựa trên cái URL vừa gửi
        foreach ($responses as $nodeUrl => $response) {
            
            $parsedUrl = parse_url($nodeUrl);
            $nodeHost = $parsedUrl['host'] ?? 'Unknown_Host';

            // KẾT ÁN: Nếu mất kết nối, ném mã lỗi kèm theo Tên miền và Mã lỗi thực tế
            if ($response instanceof \Exception || !$response->ok()) {
                $errorDetail = $response instanceof \Exception ? $response->getMessage() : "HTTP Status " . $response->status();
                throw new \Exception("NODE_DEAD|$nodeHost|$errorDetail");
            }
            
            $status = $response->json('status');
            
            // Xử lý báo lỗi cướp phòng
            if (strpos($endpoint, 'can-commit') !== false && $status === 'NO') {
                throw new \Exception("Rất tiếc! Phòng số {$payload['room_id']} vừa bị khách khác khóa trước...");
            }

            if ($status !== $expectedStatus) {
                return false; 
            }
        }
        return true;
    }
}