<?php

namespace Modules\HexawebVoiceNotes\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;

class HexawebVoiceNotesServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->loadRoutesFrom(__DIR__ . '/../Http/routes.php');
        $this->loadViewsFrom(__DIR__ . '/../Resources/views', 'hexawebvoicenotes');

        $this->runMigration();
        $this->registerMenuLink();
        $this->registerRecorderJs();
        $this->registerBodyFilter();
        $this->registerLarouteRoutes();
    }

    public function register() {}

    /**
     * Create the voice_notes table if it doesn't exist.
     */
    private function runMigration()
    {
        try {
            if (!Schema::hasTable('voice_notes')) {
                Schema::create('voice_notes', function ($table) {
                    $table->increments('id');
                    $table->unsignedInteger('thread_id')->nullable()->index();
                    $table->unsignedInteger('conversation_id')->nullable()->index();
                    $table->string('file_path', 500);
                    $table->unsignedInteger('duration_seconds')->default(0);
                    $table->string('mime_type', 127)->default('audio/mpeg');
                    $table->unsignedBigInteger('file_size')->default(0);
                    $table->string('original_name', 255)->nullable();
                    $table->string('token', 64)->unique();
                    $table->unsignedInteger('created_by')->nullable();
                    $table->timestamps();
                });
            }
        } catch (\Exception $e) {
            \Log::error('HexawebVoiceNotes migration error: ' . $e->getMessage());
        }
    }

    /**
     * Add "Voice Notes" to the Manage menu.
     */
    private function registerMenuLink()
    {
        \Eventy::addAction('menu.manage.after_mailboxes', function () {
            if (!\Auth::user() || !\Auth::user()->isAdmin()) {
                return;
            }
            echo '<li><a href="' . route('voicenotes.settings') . '"><i class="glyphicon glyphicon-record"></i> Voice Notes</a></li>';
        });
    }

    /**
     * Load the recorder JS file + pass max duration setting.
     */
    private function registerRecorderJs()
    {
        \Eventy::addAction('javascript', function () {
            $maxDuration = (int) \Option::get('voicenotes.max_duration', 300);
            $jsUrl = \Module::getPublicPath('hexawebvoicenotes');

            // Pass settings to JS
            echo "window._hvnMaxSeconds = " . $maxDuration . ";\n";

            // Load the recorder script inline (since FreeScout modules
            // don't have a standard public asset pipeline)
            $jsPath = __DIR__ . '/../Resources/js/recorder.js';
            if (file_exists($jsPath)) {
                readfile($jsPath);
            }
        }, 20);
    }

    /**
     * Filter thread body output to enhance voice note links with audio players.
     * This runs server-side so players render without client JS on page load.
     */
    private function registerBodyFilter()
    {
        \Eventy::addFilter('thread.body_output', function ($body, $thread, $conversation, $mailbox) {
            if (empty($body)) return $body;

            // Match voice note links: <a ... data-voicenote-id="123" ... >text</a>
            // Also match the legacy pattern: <a ... title="voice-note" ... >text</a>
            $body = preg_replace_callback(
                '/<a\s[^>]*(?:data-voicenote-id=["\'](\d+)["\']|title=["\']voice-note["\'])[^>]*href=["\']([^"\']+)["\'][^>]*>.*?<\/a>/is',
                function ($matches) {
                    $vnId = !empty($matches[1]) ? $matches[1] : null;
                    $url  = $matches[2];

                    // Try to get duration from data attribute
                    $duration = '';
                    if (preg_match('/data-voicenote-duration=["\']([^"\']+)["\']/', $matches[0], $dm)) {
                        $duration = $dm[1];
                    }

                    // If we have a voicenote ID, try to get fresh signed URL
                    if ($vnId) {
                        try {
                            $vn = \Modules\HexawebVoiceNotes\Models\VoiceNote::find($vnId);
                            if ($vn) {
                                $url = $vn->generateSignedUrl();
                                $duration = $duration ?: $vn->getFormattedDuration();
                            }
                        } catch (\Exception $e) {
                            // Fall through with original URL
                        }
                    }

                    $durationLabel = $duration ? ' (' . htmlspecialchars($duration) . ')' : '';

                    return '<div class="voice-note-player" style="margin:8px 0 12px;background:#fdf2f2;border:1px solid #f0d0d0;border-radius:10px;padding:12px 16px;">'
                        . '<div style="font-size:12px;font-weight:600;color:#e74c3c;margin-bottom:6px;">&#127908; Voice Note' . $durationLabel . '</div>'
                        . '<audio controls preload="none" style="width:100%;height:40px;">'
                        . '<source src="' . htmlspecialchars($url) . '" type="audio/mpeg">'
                        . '<source src="' . htmlspecialchars($url) . '" type="audio/webm">'
                        . '</audio></div>';
                },
                $body
            );

            // Second pass: catch links where href comes before data attributes
            $body = preg_replace_callback(
                '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*(?:data-voicenote-id=["\'](\d+)["\']|title=["\']voice-note["\'])[^>]*>.*?<\/a>/is',
                function ($matches) {
                    $url  = $matches[1];
                    $vnId = !empty($matches[2]) ? $matches[2] : null;

                    $duration = '';
                    if (preg_match('/data-voicenote-duration=["\']([^"\']+)["\']/', $matches[0], $dm)) {
                        $duration = $dm[1];
                    }

                    if ($vnId) {
                        try {
                            $vn = \Modules\HexawebVoiceNotes\Models\VoiceNote::find($vnId);
                            if ($vn) {
                                $url = $vn->generateSignedUrl();
                                $duration = $duration ?: $vn->getFormattedDuration();
                            }
                        } catch (\Exception $e) {}
                    }

                    $durationLabel = $duration ? ' (' . htmlspecialchars($duration) . ')' : '';

                    return '<div class="voice-note-player" style="margin:8px 0 12px;background:#fdf2f2;border:1px solid #f0d0d0;border-radius:10px;padding:12px 16px;">'
                        . '<div style="font-size:12px;font-weight:600;color:#e74c3c;margin-bottom:6px;">&#127908; Voice Note' . $durationLabel . '</div>'
                        . '<audio controls preload="none" style="width:100%;height:40px;">'
                        . '<source src="' . htmlspecialchars($url) . '" type="audio/mpeg">'
                        . '<source src="' . htmlspecialchars($url) . '" type="audio/webm">'
                        . '</audio></div>';
                },
                $body
            );

            return $body;
        }, 20, 4);
    }

    /**
     * Register the voicenotes.upload route with laroute so JS can resolve it.
     */
    private function registerLarouteRoutes()
    {
        \Eventy::addFilter('laravel_route_js.routes', function ($routes) {
            $routes[] = 'voicenotes.upload';
            $routes[] = 'voicenotes.stream';
            return $routes;
        });
    }
}
