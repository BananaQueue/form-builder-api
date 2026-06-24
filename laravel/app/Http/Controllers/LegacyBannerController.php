<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class LegacyBannerController extends Controller
{
    public function upload(Request $request): JsonResponse
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }

        if (! $request->hasFile('banner')) {
            return response()->json(['success' => false, 'error' => 'No file uploaded']);
        }

        $file = $request->file('banner');
        if (! $file || ! $file->isValid()) {
            $error = $file ? $file->getError() : UPLOAD_ERR_NO_FILE;
            return response()->json(['success' => false, 'error' => 'Upload error code: '.$error]);
        }

        $maxBytes = 2 * 1024 * 1024;
        $fileSize = (int) $file->getSize();
        if ($fileSize <= 0 || $fileSize > $maxBytes) {
            return response()->json(['success' => false, 'error' => 'PNG file must be 2 MB or smaller']);
        }

        $mimeType = $file->getMimeType();
        if ($mimeType !== 'image/png') {
            return response()->json(['success' => false, 'error' => 'Only PNG files are allowed']);
        }

        $imageInfo = @getimagesize($file->getRealPath());
        if (! $imageInfo || ($imageInfo[2] ?? null) !== IMAGETYPE_PNG) {
            return response()->json(['success' => false, 'error' => 'Only PNG files are allowed']);
        }

        $uploadDir = public_path('uploads');
        if (! is_dir($uploadDir) && ! mkdir($uploadDir, 0755, true) && ! is_dir($uploadDir)) {
            return response()->json(['success' => false, 'error' => 'Failed to save file'], 500);
        }

        try {
            $file->move($uploadDir, 'banner.png');
            $this->audit($request, 'BANNER_UPLOADED', [
                'entity_type' => 'banner',
                'entity_label' => 'banner.png',
                'metadata' => [
                    'size' => $fileSize,
                    'mime_type' => $mimeType,
                ],
            ]);

            return response()->json(['success' => true]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['success' => false, 'error' => 'Failed to save file'], 500);
        }
    }

    public function remove(Request $request): JsonResponse
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }

        $dest = public_path('uploads/banner.png');
        if (! file_exists($dest)) {
            return response()->json(['success' => false, 'error' => 'No banner to remove']);
        }

        if (! @unlink($dest)) {
            return response()->json(['success' => false, 'error' => 'Failed to remove banner'], 500);
        }

        $this->audit($request, 'BANNER_REMOVED', [
            'entity_type' => 'banner',
            'entity_label' => 'banner.png',
        ]);

        return response()->json(['success' => true]);
    }

    private function requireAuth(Request $request): ?JsonResponse
    {
        if ($request->session()->get('logged_in') !== true) {
            return response()->json(['error' => 'Authentication required'], 401);
        }

        return null;
    }

    private function audit(Request $request, string $action, array $data): void
    {
        try {
            DB::table('audit_logs')->insert([
                'actor_user_id' => $request->session()->get('user_id'),
                'actor_username' => $request->session()->get('username'),
                'actor_role' => $request->session()->get('role'),
                'action' => $action,
                'entity_type' => $data['entity_type'] ?? null,
                'entity_id' => $data['entity_id'] ?? null,
                'entity_label' => $data['entity_label'] ?? null,
                'metadata' => json_encode($data['metadata'] ?? new \stdClass(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}