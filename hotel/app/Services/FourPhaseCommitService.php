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
        // Dùng array_values để ép PHP đánh lại số thứ tự mảng chuẩn 100%
        $this->nodes = array_values(array_filter([
            env('NODE_1_URL'), env('NODE_2_URL'), env('NODE_3_URL'),
            env('NODE_4_URL'), env('NODE_5_URL')
        ]));
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

            // Tạm dừng để Demo
            sleep(10); 

            if (!$this->phase3DoCommit($transactionId)) {
                $this->abortTransaction($transactionId, "LỖI CHỐT HẠ", $roomId, $customerName);
                return false;
            }

            return true; 

        } catch (\Exception $e) {
            $errorMsg = $e->getMessage();
            
            // Nếu bắt đúng mã lỗi NODE_DEAD từ hàm sendParallelRequests
            if (strpos($errorMsg, 'NODE_DEAD|') !== false) {
                // Tách lấy cái Cổng bị chết (VD: 8003)
                $port = explode('|', $errorMsg)[1];
                $reason = "HỦY DO NODE $port CHẾT"; 
                
                // Bắn lệnh Hủy kèm theo Lý do sang cho các Node đang sống
                $this->abortTransaction($transactionId, $reason, $roomId, $customerName);
                
                // Đá văng trang chủ với thông báo lỗi chuẩn xác
               throw new \Exception("Lỗi kết nối Node: " . $url . " | Nguyên nhân thật: " . $e->getMessage());
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
        return $this->sendParallelRequests('/can-commit', ['id' => $data['id'], 'room_id' => $data['room_id']], 'YES');
    }

    private function phase2PreCommit($transactionId, $roomId = null, $customerName = null): bool
    {
        return $this->sendParallelRequests('/pre-commit', ['transaction_id' => $transactionId, 'room_id' => $roomId, 'customer_name' => $customerName], 'ACK');
    }

    private function phase3DoCommit($transactionId): bool
    {
        return $this->sendParallelRequests('/do-commit', ['transaction_id' => $transactionId], 'SUCCESS');
    }

    public function abortTransaction($transactionId, $reason = "ABORTED", $roomId = null, $customerName = null)
    {
        if (empty($this->nodes)) return;
        
        foreach ($this->nodes as $nodeUrl) {
            try {
                Http::timeout(3)->post($nodeUrl . '/abort', [
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

        // Gửi lệnh song song, dùng chính URL làm Chìa khóa (Alias) để không bao giờ bị lệch mảng
        $responses = Http::pool(function (Pool $pool) use ($endpoint, $payload) {
            foreach ($this->nodes as $nodeUrl) {
                $pool->as($nodeUrl)->timeout(5)->post($nodeUrl . $endpoint, $payload);
            }
        });

        // Duyệt kết quả dựa trên cái URL vừa gửi
        foreach ($responses as $nodeUrl => $response) {
            // Cắt cái URL ra để lấy đúng số Cổng (VD: 8003)
            $parsedUrl = parse_url($nodeUrl);
            $nodePort = $parsedUrl['port'] ?? 'Unknown';

            // KẾT ÁN: Nếu mất kết nối, ném thẳng mã lỗi chứa số cổng lên trên
            if ($response instanceof \Exception || !$response->ok()) {
                throw new \Exception("NODE_DEAD|$nodePort");
            }
            
            $status = $response->json('status');
            
            // Xử lý báo lỗi cướp phòng
            if ($endpoint === '/can-commit' && $status === 'NO') {
                throw new \Exception("Rất tiếc! Phòng số {$payload['room_id']} vừa bị khách khác khóa trước...");
            }

            if ($status !== $expectedStatus) {
                return false; 
            }
        }
        return true;
    }
}