<?php

namespace Modules\HexawebVoiceNotes\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\HexawebVoiceNotes\Models\VoiceNote;

class VoiceNoteController extends Controller
{
    /**
     * Upload a voice note, convert to MP3, store, return metadata.
     */
    public function upload(Request $request)
    {
        if (!$request->hasFile("file")) {
            return response()->json(["status" => "error", "msg" => "No file uploaded."], 400);
        }

        $file = $request->file("file");
        $maxSize = (int) \Option::get("voicenotes.max_file_size_mb", 25) * 1024 * 1024;

        if ($file->getSize() > $maxSize) {
            return response()->json(["status" => "error", "msg" => "File too large."], 400);
        }

        $duration = (int) $request->input("duration", 0);
        $conversationId = $request->input("conversation_id");

        // Store the uploaded file temporarily
        $storageDir = storage_path("app/private/" . VoiceNote::STORAGE_DIR);
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }

        $token = Str::random(48);
        $baseName = "vn_" . time() . "_" . Str::random(8);
        $tempPath = $storageDir . "/" . $baseName . "_tmp." . ($file->getClientOriginalExtension() ?: "webm");

        $file->move($storageDir, basename($tempPath));

        // Convert to MP3 via ffmpeg
        $mp3Name = $baseName . ".mp3";
        $mp3Path = $storageDir . "/" . $mp3Name;
        $bitrate = \Option::get("voicenotes.bitrate", "128k");

        $ffmpeg = $this->findFfmpeg();
        $cmd = escapeshellcmd($ffmpeg)
            . " -i " . escapeshellarg($tempPath)
            . " -codec:a libmp3lame -b:a " . escapeshellarg($bitrate)
            . " -y " . escapeshellarg($mp3Path)
            . " 2>&1";

        exec($cmd, $output, $exitCode);

        // If ffmpeg fails, keep original format
        if ($exitCode !== 0 || !file_exists($mp3Path)) {
            $finalPath = basename($tempPath);
            $mimeType = $file->getClientMimeType() ?: "audio/webm";
        } else {
            $finalPath = $mp3Name;
            $mimeType = "audio/mpeg";
            @unlink($tempPath);
        }

        // Probe actual duration via ffprobe if available
        if ($duration <= 0) {
            $duration = $this->probeDuration($storageDir . "/" . $finalPath);
        }

        $fullPath = $storageDir . "/" . $finalPath;
        $fileSize = file_exists($fullPath) ? filesize($fullPath) : 0;

        $voiceNote = VoiceNote::create([
            "file_path"        => $finalPath,
            "duration_seconds" => $duration,
            "mime_type"        => $mimeType,
            "file_size"        => $fileSize,
            "original_name"    => $file->getClientOriginalName(),
            "token"            => $token,
            "conversation_id"  => $conversationId,
            "created_by"       => Auth::id(),
        ]);

        $streamUrl = $voiceNote->generateSignedUrl();

        return response()->json([
            "status"       => "success",
            "voicenote_id" => $voiceNote->id,
            "url"          => $streamUrl,
            "duration"     => $voiceNote->getFormattedDuration(),
            "duration_sec" => $duration,
            "size"         => $fileSize,
            "mime"         => $mimeType,
        ]);
    }

    /**
     * Stream a voice note (public, signed URL).
     */
    public function stream(Request $request, $id)
    {
        $expires   = $request->query("expires", "");
        $signature = $request->query("signature", "");

        if (!VoiceNote::verifySignature($id, $expires, $signature)) {
            abort(403, "Invalid or expired link.");
        }

        $voiceNote = VoiceNote::findOrFail($id);
        $path = $voiceNote->getStoragePath();

        if (!file_exists($path)) {
            abort(404, "File not found.");
        }

        return response()->file($path, [
            "Content-Type"        => $voiceNote->mime_type,
            "Content-Disposition" => "inline; filename=\"voice-note-" . $voiceNote->id . "." . ($voiceNote->mime_type === "audio/mpeg" ? "mp3" : "webm") . "\"",
            "Cache-Control"       => "public, max-age=86400",
        ]);
    }

    /**
     * Settings page.
     */
    public function settings()
    {
        $settings = [
            "max_duration"    => (int) \Option::get("voicenotes.max_duration", 300),
            "bitrate"         => \Option::get("voicenotes.bitrate", "128k"),
            "url_expiry_days" => (int) \Option::get("voicenotes.url_expiry_days", 7),
            "max_file_size_mb"=> (int) \Option::get("voicenotes.max_file_size_mb", 25),
        ];

        $ffmpegPath = $this->findFfmpeg();
        $ffmpegInstalled = !empty($ffmpegPath) && file_exists($ffmpegPath);
        $ffmpegVersion = "";
        if ($ffmpegInstalled) {
            exec(escapeshellcmd($ffmpegPath) . " -version 2>&1", $vOut);
            $ffmpegVersion = !empty($vOut[0]) ? $vOut[0] : "unknown";
        }

        return view("hexawebvoicenotes::settings", compact("settings", "ffmpegInstalled", "ffmpegVersion"));
    }

    /**
     * Save settings.
     */
    public function saveSettings(Request $request)
    {
        \Option::set("voicenotes.max_duration", max(30, min(600, (int) $request->input("max_duration", 300))));
        \Option::set("voicenotes.bitrate", in_array($request->input("bitrate"), ["64k", "96k", "128k", "192k"]) ? $request->input("bitrate") : "128k");
        \Option::set("voicenotes.url_expiry_days", max(1, min(90, (int) $request->input("url_expiry_days", 7))));
        \Option::set("voicenotes.max_file_size_mb", max(1, min(100, (int) $request->input("max_file_size_mb", 25))));

        return redirect()->route("voicenotes.settings")->with("flash_success", "Voice note settings saved.");
    }

    private function findFfmpeg()
    {
        foreach (["/usr/local/bin/ffmpeg", "/usr/bin/ffmpeg"] as $p) {
            if (file_exists($p)) return $p;
        }
        $which = trim(shell_exec("which ffmpeg 2>/dev/null") ?: "");
        return $which ?: "/usr/local/bin/ffmpeg";
    }

    private function probeDuration($filePath)
    {
        $ffprobe = str_replace("ffmpeg", "ffprobe", $this->findFfmpeg());
        if (!file_exists($ffprobe)) return 0;

        $cmd = escapeshellcmd($ffprobe)
            . " -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 "
            . escapeshellarg($filePath) . " 2>/dev/null";
        $out = trim(shell_exec($cmd) ?: "");

        return $out ? (int) round((float) $out) : 0;
    }
}
