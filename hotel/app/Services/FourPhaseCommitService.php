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
        $nodes = [
            'https://node-1-khanh.onrender.com',
            'https://node-2-khai.onrender.com',
            'https://node-3-khaiii.onrender.com', 
            'https://node-4-kien.onrender.com',
            'https://node-5-duy.onrender.com'
        ];
        $this->nodes = array_values(array_filter($nodes));
    }

    public function executeTransaction(array $bookingData): bool
    {
        if (count($this->nodes) !== 5) {
            throw new \Exception("Lỗi 4PC: Yêu cầu chính xác 5 Server. Hiện chỉ có " . count($this->nodes) . " Server!");
        }

        $transactionId = $bookingData['id'];
        $roomId = $bookingData['room_id'] ?? null;
        $customerName = $bookingData['name'] ?? null;
        $pointOfNoReturn = false; 

        try {
            if (!$this->phase1CanCommit($bookingData)) {
                $this->abortTransaction($transactionId, "TỪ CHỐI (PHA 1)", $roomId, $customerName);
                return false;
            }

            if (!$this->phase2PreCommit($transactionId, $roomId, $customerName)) {
                $this->abortTransaction($transactionId, "TỪ CHỐI (PHA 2)", $roomId, $customerName);
                return false;
            }

            $pointOfNoReturn = true;
            sleep(10); 

            $this->phase3DoCommit($transactionId);
            return true; 

        } catch (\Exception $e) {
            $errorMsg = $e->getMessage();
            
            if ($pointOfNoReturn) {
                Log::warning("Giao dịch $transactionId thành công. Lỗi Do-Commit: " . $errorMsg);
                return true; 
            }

            // PHÂN LOẠI LỖI CHUẨN XÁC: CHẾT MẠNG vs CƯỚP PHÒNG
            if (strpos($errorMsg, 'NODE_DEAD|') !== false) {
                $parts = explode('|', $errorMsg);
                $host = $parts[1] ?? 'Unknown_Node';
                $reason = "HỦY DO NODE [$host] TỪ CHỐI/CHẾT"; 
                
                $this->abortTransaction($transactionId, $reason, $roomId, $customerName);
                throw new \Exception("Chi tiết lỗi mạng: Node $host không phản hồi | Nguyên nhân: " . ($parts[2] ?? 'Timeout'));
            } 
            elseif (strpos($errorMsg, 'CƯỚP PHÒNG') !== false) {
                // CHỈ BÁO CƯỚP PHÒNG KHI ĐÚNG LÀ CƯỚP PHÒNG
                $this->abortTransaction($transactionId, "ABORTED (BỊ CƯỚP PHÒNG)", $roomId, $customerName);
                throw $e; 
            } 
            else {
                // LỖI KHÁC BẤT NGỜ (Tránh vơ đũa cả nắm)
                $this->abortTransaction($transactionId, "ABORTED (LỖI HỆ THỐNG)", $roomId, $customerName);
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
                Http::withoutVerifying()->timeout(3)->post($nodeUrl . '/api/abort', [
                    'transaction_id' => $transactionId,
                    'reason' => $reason,
                    'room_id' => $roomId,
                    'customer_name' => $customerName
                ]);
            } catch (\Exception $e) {}
        }
    }

    private function sendParallelRequests($endpoint, $payload, $expectedStatus): bool
    {
        if (empty($this->nodes)) return true; 

        $responses = Http::pool(function (Pool $pool) use ($endpoint, $payload) {
            foreach ($this->nodes as $nodeUrl) {
                $pool->as($nodeUrl)->withoutVerifying()->connectTimeout(3)->timeout(15)->post($nodeUrl . $endpoint, $payload);
            }
        });

        $deadNodeError = null;    
        $timeoutNodeError = null; 
        $roomHijackedError = null; // Thêm biến lưu trạng thái Cướp phòng

        // BƯỚC 1: DUYỆT TÌM BẰNG HẾT LỖI CỦA 5 NODE (Không throw giữa chừng)
        foreach ($responses as $nodeUrl => $response) {
            $parsedUrl = parse_url($nodeUrl);
            $nodeHost = $parsedUrl['host'] ?? 'Unknown_Host';

            if ($response instanceof \Exception) {
                $errorMsg = $response->getMessage();
                if (strpos($errorMsg, 'timed out') !== false || strpos($errorMsg, 'cURL error 28') !== false) {
                    $timeoutNodeError = "NODE_DEAD|$nodeHost|Quá tải (Timeout)";
                } else {
                    $deadNodeError = "NODE_DEAD|$nodeHost|Server đang tắt hoặc sập nguồn";
                }
                continue; 
            }
            
            if (!$response->ok()) {
                $deadNodeError = "NODE_DEAD|$nodeHost|Lỗi HTTP " . $response->status();
                continue;
            }
            
            $status = $response->json('status');
            
            // Nếu có Node báo NO, lưu vào biến chờ xét xử, không throw ngay!
            if (strpos($endpoint, 'can-commit') !== false && $status === 'NO') {
                $roomHijackedError = "CƯỚP PHÒNG|Rất tiếc! Phòng số {$payload['room_id']} vừa bị khách khác khóa trước...";
                continue; 
            }

            if ($status !== $expectedStatus) {
                return false; 
            }
        }

        // BƯỚC 2: TÒA TUYÊN ÁN THEO ĐÚNG ĐỘ ƯU TIÊN (LOGIC VÀNG)
        // Ưu tiên 1: Đứt mạng, chết nguồn (Nghiêm trọng nhất - Bác bỏ mọi trạng thái phòng)
        if ($deadNodeError) {
            throw new \Exception($deadNodeError); 
        }
        // Ưu tiên 2: Mạng chậm (Cũng là đứt kết nối)
        if ($timeoutNodeError) {
            throw new \Exception($timeoutNodeError); 
        }
        // Ưu tiên 3: Mạng khỏe 100%, NHƯNG phòng đã bị người khác khóa
        if ($roomHijackedError) {
            // Tách chữ "CƯỚP PHÒNG|" ra để báo lên web
            throw new \Exception(explode('|', $roomHijackedError)[1]);
        }

        return true;
    }
}