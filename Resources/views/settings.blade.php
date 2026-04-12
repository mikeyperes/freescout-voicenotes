@extends("layouts.app")

@section("title_full", "Voice Notes - " . config("app.name"))

@section("content")
<div class="container">
    <div class="row">
        <div class="col-md-8 col-md-offset-2">
            <h3>Voice Notes Settings</h3>

            @if(session("flash_success"))
                <div class="alert alert-success">{{ session("flash_success") }}</div>
            @endif

            <div class="alert alert-info" style="font-size:13px;">
                <strong>Setup:</strong> This module lets agents record voice notes directly in the reply editor.
                Recordings are converted to MP3 via ffmpeg and attached with an inline audio player.
                Customers receive a secure, time-limited link to play the voice note.
            </div>

            {{-- ffmpeg status --}}
            <div class="panel panel-default">
                <div class="panel-heading"><strong>System Status</strong></div>
                <div class="panel-body">
                    <table class="table table-condensed" style="margin-bottom:0;">
                        <tr>
                            <td style="width:180px;"><strong>ffmpeg</strong></td>
                            <td>
                                @if($ffmpegInstalled)
                                    <span class="label label-success">Installed</span>
                                    <small class="text-muted" style="margin-left:8px;">{{ $ffmpegVersion }}</small>
                                @else
                                    <span class="label label-danger">Not Found</span>
                                    <small class="text-muted" style="margin-left:8px;">Recordings will stay in WebM format.</small>
                                @endif
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            {{-- Settings form --}}
            <form method="POST" action="{{ route("voicenotes.settings.save") }}">
                {{ csrf_field() }}

                <div class="panel panel-default">
                    <div class="panel-heading"><strong>Recording</strong></div>
                    <div class="panel-body">
                        <div class="form-group">
                            <label>Max Recording Duration (seconds)</label>
                            <input type="number" name="max_duration" class="form-control" style="max-width:200px;"
                                   value="{{ $settings["max_duration"] }}" min="30" max="600">
                            <p class="help-block">30 - 600 seconds. Default: 300 (5 minutes).</p>
                        </div>
                        <div class="form-group">
                            <label>MP3 Bitrate</label>
                            <select name="bitrate" class="form-control" style="max-width:200px;">
                                @foreach(["64k" => "64 kbps (small)", "96k" => "96 kbps", "128k" => "128 kbps (recommended)", "192k" => "192 kbps (high quality)"] as $val => $label)
                                    <option value="{{ $val }}" {{ $settings["bitrate"] === $val ? "selected" : "" }}>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Max File Size (MB)</label>
                            <input type="number" name="max_file_size_mb" class="form-control" style="max-width:200px;"
                                   value="{{ $settings["max_file_size_mb"] }}" min="1" max="100">
                            <p class="help-block">1 - 100 MB. Default: 25 MB.</p>
                        </div>
                    </div>
                </div>

                <div class="panel panel-default">
                    <div class="panel-heading"><strong>Security</strong></div>
                    <div class="panel-body">
                        <div class="form-group">
                            <label>Signed URL Expiry (days)</label>
                            <input type="number" name="url_expiry_days" class="form-control" style="max-width:200px;"
                                   value="{{ $settings["url_expiry_days"] }}" min="1" max="90">
                            <p class="help-block">How long the playback link in emails remains valid. Default: 7 days.</p>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <button type="submit" class="btn btn-primary">Save Settings</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
