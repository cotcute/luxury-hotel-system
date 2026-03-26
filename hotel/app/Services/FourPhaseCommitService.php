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
        // Danh sách 5 Node vệ tinh
        $nodes = [
            'https://node-1-khanh.onrender.com',
            'https://node-2-khai.onrender.com',
            'https://node-3-khaiii.onrender.com', // Điền đúng link Node 3 của bạn vào
            'https://node-4-kien.onrender.com',
            'https://node-5-duy.onrender.com'
        ];
        
        // Lọc bỏ các khoảng trắng hoặc link bị rỗng
        $this->nodes = array_values(array_filter($nodes));
    }

    public function executeTransaction(array $bookingData): bool
    {
        // 1. CHỐT CHẶN BẢO MẬT: Bắt buộc 5 Node
        if (count($this->nodes) !== 5) {
            throw new \Exception("Lỗi 4PC Nghiêm trọng: Yêu cầu chính xác 5 Server. Hiện tại chỉ phát hiện " . count($this->nodes) . " Server hoạt động!");
        }

        $transactionId = $bookingData['id'];
        $roomId = $bookingData['room_id'] ?? null;
        $customerName = $bookingData['name'] ?? null;
        
        $pointOfNoReturn = false; // Biến đánh dấu điểm chết của 4PC

        try {
            if (!$this->phase1CanCommit($bookingData)) {
                $this->abortTransaction($transactionId, "TỪ CHỐI (PHA 1)", $roomId, $customerName);
                return false;
            }

            if (!$this->phase2PreCommit($transactionId, $roomId, $customerName)) {
                $this->abortTransaction($transactionId, "TỪ CHỐI (PHA 2)", $roomId, $customerName);
                return false;
            }

            // --- BƯỚC QUA ĐIỂM KHÔNG THỂ QUAY ĐẦU (POINT OF NO RETURN) ---
            // Từ giây phút này, 100% các Node đã hứa sẽ Commit. 
            // Nếu Đầu não hoặc Node chết ở Pha 3, Giao dịch BẮT BUỘC vẫn phải thành công!
            $pointOfNoReturn = true;

            sleep(10); // Giữ nguyên sleep để Demo

            // Bắn Pha 3 (Do-Commit)
            $this->phase3DoCommit($transactionId);

            return true; 

        } catch (\Exception $e) {
            $errorMsg = $e->getMessage();
            
            // 2. XỬ LÝ LỖI Ở ĐIỂM MÙ PHA 3
            if ($pointOfNoReturn) {
                // ĐÃ CHỐT HẠ THÌ CẤM ABORT!
                // Ghi log Node chết để cơ chế phục hồi xử lý sau, trên Web vẫn báo thành công.
                Log::warning("Giao dịch $transactionId thành công. Nhưng có Node bị đứt kết nối lúc Do-Commit: " . $errorMsg);
                return true; 
            }

            // 3. XỬ LÝ LỖI Ở PHA 1 HOẶC PHA 2 (CHƯA CHỐT HẠ) -> ABORT BÌNH THƯỜNG
            if (strpos($errorMsg, 'NODE_DEAD|') !== false) {
                $parts = explode('|', $errorMsg);
                $host = $parts[1] ?? 'Unknown_Node';
                $reason = "HỦY DO NODE [$host] TỪ CHỐI/CHẾT"; 
                
                $this->abortTransaction($transactionId, $reason, $roomId, $customerName);
                throw new \Exception("Chi tiết lỗi mạng: Node $host không phản hồi | Nguyên nhân: " . ($parts[2] ?? 'Timeout'));
            } 
            else {
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
                Http::withoutVerifying()->timeout(3)->post($nodeUrl . '/api/abort', [
                    'transaction_id' => $transactionId,
                    'reason' => $reason,
                    'room_id' => $roomId,
                    'customer_name' => $customerName
                ]);
            } catch (\Exception $e) {
                // Thằng nào chết thì kệ nó
            }
        }
    }

   private function sendParallelRequests($endpoint, $payload, $expectedStatus): bool
    {
        if (empty($this->nodes)) return true; 

        $responses = Http::pool(function (Pool $pool) use ($endpoint, $payload) {
            foreach ($this->nodes as $nodeUrl) {
                // VŨ KHÍ 1: Thêm connectTimeout(3) -> Gõ cửa 3 giây không thưa là xác định tắt máy!
                // Giữ nguyên timeout(15) -> Giới hạn xử lý 15 giây để không làm treo trang web.
                $pool->as($nodeUrl)->withoutVerifying()->connectTimeout(6)->timeout(15)->post($nodeUrl . $endpoint, $payload);
            }
        });

        // VŨ KHÍ 2: Bảng phân loại ưu tiên lỗi
        $deadNodeError = null;    // Lỗi chết hẳn (Máy tắt, từ chối kết nối) - Ưu tiên 1
        $timeoutNodeError = null; // Lỗi do mạng chậm (Timeout) - Ưu tiên 2

        foreach ($responses as $nodeUrl => $response) {
            $parsedUrl = parse_url($nodeUrl);
            $nodeHost = $parsedUrl['host'] ?? 'Unknown_Host';

            if ($response instanceof \Exception) {
                $errorMsg = $response->getMessage();
                
                // Phân loại lỗi
                if (strpos($errorMsg, 'timed out') !== false || strpos($errorMsg, 'cURL error 28') !== false) {
                    $timeoutNodeError = "NODE_DEAD|$nodeHost|Quá tải (Timeout)";
                } else {
                    // Lỗi từ chối kết nối, sập nguồn (cURL error 7, error 6...)
                    $deadNodeError = "NODE_DEAD|$nodeHost|Server đang tắt hoặc sập nguồn";
                }
                continue; // Ghi nhận lỗi nhưng duyệt tiếp mảng để tìm trùm cuối!
            }
            
            if (!$response->ok()) {
                $deadNodeError = "NODE_DEAD|$nodeHost|Lỗi HTTP " . $response->status();
                continue;
            }
            
            $status = $response->json('status');
            
            if (strpos($endpoint, 'can-commit') !== false && $status === 'NO') {
                throw new \Exception("Rất tiếc! Phòng số {$payload['room_id']} vừa bị khách khác khóa trước...");
            }

            if ($status !== $expectedStatus) {
                return false; 
            }
        }

        // TÒA TUYÊN ÁN (Xử lý theo thứ tự ưu tiên)
        if ($deadNodeError) {
            throw new \Exception($deadNodeError); // Có thằng tắt máy -> Chửi thằng tắt máy trước!
        }
        if ($timeoutNodeError) {
            throw new \Exception($timeoutNodeError); // Không ai tắt máy, chỉ có thằng chậm -> Chửi thằng chậm.
        }

        return true;
    }
}