/**
 * HexawebVoiceNotes — Recorder v1.1.0
 * Browser-side voice recording with Summernote toolbar integration.
 */
(function($) {
    "use strict";

    var VN_MAX_SECONDS = window._hvnMaxSeconds || 300;
    var _recorder = null;
    var _stream   = null;
    var _chunks   = [];
    var _timer    = null;
    var _seconds  = 0;

    // ── Summernote toolbar button ──
    var VoiceNoteButton = function(context) {
        var ui = $.summernote.ui;
        var button = ui.button({
            contents: '<i class="glyphicon glyphicon-record" style="color:#e74c3c;"></i>',
            tooltip: "Record Voice Note",
            className: "note-btn-voicenote",
            container: "body",
            click: function() { startRecording(); }
        });
        return button.render();
    };

    if (typeof fs_conv_editor_buttons !== "undefined") {
        fs_conv_editor_buttons["voicenote"] = VoiceNoteButton;
    }

    if (typeof fsAddFilter !== "undefined") {
        fsAddFilter("conversation.editor_toolbar", function(toolbar) {
            for (var i = 0; i < toolbar.length; i++) {
                if (toolbar[i][0] === "style") {
                    toolbar[i][1].push("voicenote");
                    break;
                }
            }
            return toolbar;
        });
    }

    // ── Recording ──
    function startRecording() {
        if (_recorder && _recorder.state === "recording") return;
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            showFloatingAlert("error", "Your browser does not support microphone recording.");
            return;
        }

        navigator.mediaDevices.getUserMedia({ audio: true }).then(function(stream) {
            _stream  = stream;
            _chunks  = [];
            _seconds = 0;

            var mimeType = "audio/webm;codecs=opus";
            if (typeof MediaRecorder.isTypeSupported === "function") {
                if (!MediaRecorder.isTypeSupported(mimeType)) {
                    mimeType = MediaRecorder.isTypeSupported("audio/webm") ? "audio/webm" : "";
                }
            }
            _recorder = mimeType
                ? new MediaRecorder(stream, { mimeType: mimeType })
                : new MediaRecorder(stream);

            _recorder.ondataavailable = function(e) {
                if (e.data && e.data.size > 0) _chunks.push(e.data);
            };
            _recorder.onstop = function() { onRecordingStop(); };
            _recorder.start(250);
            showOverlay();
            _timer = setInterval(function() {
                _seconds++;
                updateTimerDisplay();
                if (_seconds >= VN_MAX_SECONDS) stopRecording();
            }, 1000);
        }).catch(function(err) {
            showFloatingAlert("error", "Microphone access denied: " + err.message);
        });
    }

    function stopRecording() {
        if (_recorder && _recorder.state === "recording") {
            _recorder.stop();
        }
        cleanup();
    }

    function cancelRecording() {
        if (_recorder && _recorder.state === "recording") {
            _recorder.ondataavailable = null;
            _recorder.onstop = null;
            _recorder.stop();
        }
        _chunks = [];
        cleanup();
        removeOverlay();
    }

    function cleanup() {
        clearInterval(_timer);
        _timer = null;
        if (_stream) {
            _stream.getTracks().forEach(function(t) { t.stop(); });
            _stream = null;
        }
    }

    function onRecordingStop() {
        removeOverlay();
        if (!_chunks.length) return;

        var blob = new Blob(_chunks, { type: _recorder.mimeType || "audio/webm" });
        var ext = (blob.type.indexOf("mp4") !== -1) ? "mp4" : "webm";
        var fname = "voice-note-" + Date.now() + "." + ext;
        var file = new File([blob], fname, { type: blob.type });
        var duration = _seconds;

        uploadVoiceNote(file, duration);
    }

    // ── Upload ──
    function uploadVoiceNote(file, durationSec) {
        ajaxSetup();
        var data = new FormData();
        data.append("file", file);
        data.append("duration", durationSec);

        // Try to get conversation_id from the page
        var convId = $("body").attr("data-conversation_id") ||
                     (typeof getGlobalAttr === "function" ? getGlobalAttr("conversation_id") : "") || "";
        if (convId) data.append("conversation_id", convId);

        // Build upload URL — use Vars.public_url base (laroute may not have module routes)
        var uploadUrl = Vars.public_url + "/voicenotes/upload";

        // Show uploading indicator
        var dummyId = "hvn-" + Date.now();
        var container = $(".attachments-upload:first");
        var li = '<li class="atachment-upload-' + dummyId + '">'
            + '<img src="' + Vars.public_url + '/img/loader-tiny.gif" width="16" height="16"/> '
            + '<span class="break-words">Converting voice note...</span>'
            + '</li>';
        container.children("ul:first").append(li);
        container.show();

        $.ajax({
            url: uploadUrl,
            data: data,
            cache: false,
            contentType: false,
            processData: false,
            type: "POST",
            success: function(r) {
                if (r.status !== "success" || !r.url) {
                    showFloatingAlert("error", r.msg || "Voice note upload failed.");
                    $("li.atachment-upload-" + dummyId).remove();
                    if (!container.find("li").length) container.hide();
                    return;
                }

                // Update the upload indicator
                $("li.atachment-upload-" + dummyId).html(
                    '<a href="' + r.url + '" class="break-words" target="_blank">'
                    + 'Voice Note (' + r.duration + ')</a> '
                    + '<span class="text-help">(' + formatBytes(r.size) + ')</span>'
                );

                fs_reply_changed = true;

                // Insert voice note marker into the editor body.
                // The server-side filter will enhance this to an audio player on display.
                var marker = '<p>'
                    + '<a href="' + r.url + '"'
                    + ' data-voicenote-id="' + r.voicenote_id + '"'
                    + ' data-voicenote-duration="' + r.duration + '"'
                    + ' class="voice-note-link"'
                    + ' style="display:inline-block;background:#fdf2f2;border:1px solid #e74c3c;border-radius:8px;padding:8px 16px;color:#e74c3c;font-weight:600;text-decoration:none;font-size:14px;">'
                    + '&#127908; Voice Note (' + r.duration + ')'
                    + '</a></p><p><br></p>';
                $("#body").summernote("pasteHTML", marker);
            },
            error: function() {
                $("li.atachment-upload-" + dummyId).remove();
                if (!container.find("li").length) container.hide();
                showFloatingAlert("error", "Voice note upload failed.");
            }
        });
    }

    // ── Recording overlay UI ──
    function showOverlay() {
        if ($("#hvn-overlay").length) return;
        var html = '<div id="hvn-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:10500;display:flex;align-items:center;justify-content:center;">'
            + '<div style="background:#fff;border-radius:12px;width:360px;max-width:90vw;box-shadow:0 8px 32px rgba(0,0,0,0.3);text-align:center;overflow:hidden;">'
            + '<div style="padding:24px 20px 16px;">'
            + '<div id="hvn-pulse" style="display:inline-block;width:16px;height:16px;background:#e74c3c;border-radius:50%;margin-right:8px;animation:hvn-pulse 1s ease-in-out infinite;"></div>'
            + '<span style="font-size:16px;font-weight:600;color:#333;">Recording...</span>'
            + '<div id="hvn-timer" style="font-size:36px;font-weight:700;color:#e74c3c;margin:16px 0;font-variant-numeric:tabular-nums;">0:00</div>'
            + '<div style="font-size:12px;color:#999;margin-bottom:8px;">Max ' + Math.floor(VN_MAX_SECONDS / 60) + ' minutes</div>'
            + '</div>'
            + '<div style="padding:12px 20px 20px;display:flex;gap:10px;justify-content:center;">'
            + '<button type="button" id="hvn-cancel" class="btn btn-default" style="min-width:100px;">Cancel</button>'
            + '<button type="button" id="hvn-stop" class="btn btn-danger" style="min-width:140px;font-weight:600;">&#9632; Stop & Attach</button>'
            + '</div></div></div>';

        $("body").append(html);

        if (!$("#hvn-pulse-style").length) {
            $("body").append('<style id="hvn-pulse-style">@keyframes hvn-pulse{0%,100%{opacity:1;transform:scale(1);}50%{opacity:.4;transform:scale(1.3);}}</style>');
        }

        $("#hvn-stop").on("click", function() { stopRecording(); });
        $("#hvn-cancel").on("click", function() { cancelRecording(); });
    }

    function removeOverlay() {
        $("#hvn-overlay").remove();
    }

    function updateTimerDisplay() {
        $("#hvn-timer").text(formatTime(_seconds));
    }

    // ── Thread display: inject audio players for voice note links ──
    $(function() {
        $("a.voice-note-link, a[data-voicenote-id]").each(function() {
            var $a   = $(this);
            var url  = $a.attr("href");
            var dur  = $a.attr("data-voicenote-duration") || "";
            if (!url) return;

            var player = '<div class="voice-note-player" style="margin:8px 0 12px;background:#fdf2f2;border:1px solid #f0d0d0;border-radius:10px;padding:12px 16px;">'
                + '<div style="font-size:12px;font-weight:600;color:#e74c3c;margin-bottom:6px;">&#127908; Voice Note' + (dur ? ' (' + dur + ')' : '') + '</div>'
                + '<audio controls preload="none" style="width:100%;height:40px;">'
                + '<source src="' + url + '" type="audio/mpeg">'
                + '<source src="' + url + '" type="audio/webm">'
                + 'Your browser does not support audio playback.'
                + '</audio></div>';

            $a.replaceWith(player);
        });
    });

    // ── Helpers ──
    function formatTime(s) {
        var m = Math.floor(s / 60);
        var sec = s % 60;
        return m + ":" + (sec < 10 ? "0" : "") + sec;
    }

    function formatBytes(bytes) {
        if (bytes < 1024) return bytes + " B";
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + " KB";
        return (bytes / 1048576).toFixed(1) + " MB";
    }

})(jQuery);
