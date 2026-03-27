<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FourPhaseCommitService
{
    protected $nodes;

    public function __construct()
    {
        // 5 ĐỊA CHỈ SERVER (Đảm bảo chính xác link của nhóm bạn)
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
        if (count($this->nodes) !== 5) {
            throw new \Exception("LỖI CẤU HÌNH|Hệ Thống|Bắt buộc phải có đúng 5 Server để chạy hệ phân tán!");
        }

        $transactionId = $bookingData['id'];
        $roomId = $bookingData['room_id'] ?? null;
        $customerName = $bookingData['name'] ?? null;
        $pointOfNoReturn = false; 

        try {
            // PHA 1: HỎI Ý KIẾN (Chết 1 máy = Văng Exception ngay lập tức)
            if (!$this->phase1CanCommit($bookingData)) {
                $this->abortTransaction($transactionId, "TỪ CHỐI (PHA 1)", $roomId, $customerName);
                throw new \Exception("Giao dịch bị từ chối ở Pha 1!");
            }

            // PHA 2: CHUẨN BỊ
            if (!$this->phase2PreCommit($transactionId, $roomId, $customerName)) {
                $this->abortTransaction($transactionId, "TỪ CHỐI (PHA 2)", $roomId, $customerName);
                throw new \Exception("Giao dịch bị từ chối ở Pha 2!");
            }

            // --- BƯỚC QUA VẠCH KẺ TỬ THẦN ---
            $pointOfNoReturn = true;
            sleep(10); 

            // PHA 3: CHỐT HẠ
            $this->phase3DoCommit($transactionId);
            return true; 

        } catch (\Exception $e) {
            $errorMsg = $e->getMessage();
            
            // Nếu chết lúc đang Chốt hạ (Pha 3), không được Hủy mà phải ghi nhận Thành công
            if ($pointOfNoReturn) {
                Log::warning("Giao dịch $transactionId thành công. Lỗi Do-Commit: " . $errorMsg);
                return true; 
            }

            // BÓC TÁCH LỖI: TẮT MÁY HAY CƯỚP PHÒNG?
            if (strpos($errorMsg, 'NODE_DEAD|') !== false) {
                $parts = explode('|', $errorMsg);
                $host = $parts[1] ?? 'Unknown';
                $detail = $parts[2] ?? 'Không phản hồi';
                
                // Lệnh cho 4 máy sống phải HỦY giao dịch
                $reason = "HỦY DO NODE [$host] ĐANG BẬN/TẮT"; 
                $this->abortTransaction($transactionId, $reason, $roomId, $customerName);
                
                // Báo lỗi đỏ chót ra màn hình người dùng
                throw new \Exception("LỖI MẠNG: Server [$host] đang tắt hoặc bận. Giao dịch đã bị HỦY! (Chi tiết: $detail)");
            } 
            elseif (strpos($errorMsg, 'CƯỚP PHÒNG|') !== false) {
                $parts = explode('|', $errorMsg);
                $this->abortTransaction($transactionId, " 1 Trong 5 Serve bị tắt", $roomId, $customerName);
                throw new \Exception($parts[2] ?? 'Phòng đã bị chiếm!'); 
            } 
            else {
                $this->abortTransaction($transactionId, "ABORTED (LỖI HỆ THỐNG)", $roomId, $customerName);
                throw $e;
            }
        }
    }

    private function phase1CanCommit($data): bool {
        return $this->sendStrictRequests('/api/can-commit', ['id' => $data['id'], 'room_id' => $data['room_id']], 'YES');
    }

    private function phase2PreCommit($transactionId, $roomId = null, $customerName = null): bool {
        return $this->sendStrictRequests('/api/pre-commit', ['transaction_id' => $transactionId, 'room_id' => $roomId, 'customer_name' => $customerName], 'ACK');
    }

    private function phase3DoCommit($transactionId): bool {
        return $this->sendStrictRequests('/api/do-commit', ['transaction_id' => $transactionId], 'SUCCESS');
    }

    public function abortTransaction($transactionId, $reason = "ABORTED", $roomId = null, $customerName = null) {
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

    // =========================================================================
    // VŨ KHÍ TỐI THƯỢNG: GÕ CỬA TUẦN TỰ (BẮT TẬN TAY KẺ TẮT MÁY)
    // =========================================================================
    private function sendStrictRequests($endpoint, $payload, $expectedStatus): bool
    {
        foreach ($this->nodes as $nodeUrl) {
            $host = parse_url($nodeUrl, PHP_URL_HOST) ?? $nodeUrl;

            try {
                // Gọi từng máy một. Đợi 5 giây, nếu máy tắt mạng sẽ văng ConnectionException ngay.
                $response = Http::withoutVerifying()->timeout(5)->post($nodeUrl . $endpoint, $payload);

                // 1. Máy chạy nhưng Render báo lỗi bảo trì, 502 Bad Gateway,...
                if (!$response->ok()) {
                    throw new \Exception("NODE_DEAD|$host|Lỗi HTTP " . $response->status() . " (Server sập)");
                }

                $status = $response->json('status');

                // 2. Phòng bị khóa bởi ai đó
                if (strpos($endpoint, 'can-commit') !== false && $status === 'NO') {
                    throw new \Exception("CƯỚP PHÒNG|$host|Phòng số {$payload['room_id']} đã hủy!");
                }

                // 3. Phản hồi sai cú pháp
                if ($status !== $expectedStatus) {
                    throw new \Exception("NODE_DEAD|$host|Phản hồi sai: $status");
                }

            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                // 4. MÁY ĐÃ BỊ TẮT ĐIỆN HOẶC MẤT MẠNG 100% (BẮT ĐÚNG BỆNH)
                throw new \Exception("NODE_DEAD|$host|Máy chủ không hoạt động hoặc đang tắt!");
            } catch (\Exception $e) {
                // Giữ nguyên lỗi nếu đã được gán nhãn
                $errorMsg = $e->getMessage();
                if (strpos($errorMsg, 'NODE_DEAD|') !== false || strpos($errorMsg, 'CƯỚP PHÒNG|') !== false) {
                    throw $e;
                }
                throw new \Exception("NODE_DEAD|$host|Lỗi không rõ: " . $errorMsg);
            }
        }
        return true;
    }
}