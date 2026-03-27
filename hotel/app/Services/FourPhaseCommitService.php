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
            // LỖ HỔNG 2 ĐÃ ĐƯỢC VÁ: Ép văng Exception thay vì return false để Controller không thể bỏ qua!
            if (!$this->phase1CanCommit($bookingData)) {
                $this->abortTransaction($transactionId, "TỪ CHỐI (PHA 1)", $roomId, $customerName);
                throw new \Exception("Giao dịch bị từ chối ở Pha 1: Một số Server không đồng ý!");
            }

            if (!$this->phase2PreCommit($transactionId, $roomId, $customerName)) {
                $this->abortTransaction($transactionId, "TỪ CHỐI (PHA 2)", $roomId, $customerName);
                throw new \Exception("Giao dịch bị từ chối ở Pha 2: Lỗi Pre-Commit!");
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

            // PHÂN LOẠI LỖI CHUẨN XÁC
            if (strpos($errorMsg, 'NODE_DEAD|') !== false) {
                $parts = explode('|', $errorMsg);
                $host = $parts[1] ?? 'Unknown_Node';
                $reason = "HỦY DO NODE [$host] TỪ CHỐI/CHẾT"; 
                
                $this->abortTransaction($transactionId, $reason, $roomId, $customerName);
                throw new \Exception("Chi tiết lỗi mạng: Node $host không phản hồi | Nguyên nhân: " . ($parts[2] ?? 'Mất kết nối'));
            } 
            elseif (strpos($errorMsg, 'CƯỚP PHÒNG') !== false) {
                $this->abortTransaction($transactionId, "ABORTED (BỊ CƯỚP PHÒNG)", $roomId, $customerName);
                throw $e; 
            } 
            else {
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
                // Thay withoutVerifying() bằng withOptions để tránh lỗi ngầm của Http::pool
                $pool->as($nodeUrl)->withOptions(['verify' => false])->connectTimeout(3)->timeout(15)->post($nodeUrl . $endpoint, $payload);
            }
        });

        // LỖ HỔNG 1 ĐÃ ĐƯỢC VÁ (VŨ KHÍ TỐI THƯỢNG): Đếm đủ 5 kết quả mới cho làm tiếp!
        if (count($responses) < count($this->nodes)) {
            throw new \Exception("NODE_DEAD|Hệ Thống|Có Server bị tắt nguồn, dữ liệu không trả về!");
        }

        $deadNodeError = null;    
        $timeoutNodeError = null; 
        $roomHijackedError = null; 

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
            
            if (strpos($endpoint, 'can-commit') !== false && $status === 'NO') {
                $roomHijackedError = "CƯỚP PHÒNG|Rất tiếc! Phòng số {$payload['room_id']} vừa bị khách khác khóa trước...";
                continue; 
            }

            if ($status !== $expectedStatus) {
                return false; 
            }
        }

        // TÒA TUYÊN ÁN
        if ($deadNodeError) {
            throw new \Exception($deadNodeError); 
        }
        if ($timeoutNodeError) {
            throw new \Exception($timeoutNodeError); 
        }
        if ($roomHijackedError) {
            throw new \Exception(explode('|', $roomHijackedError)[1]);
        }

        return true;
    }
}