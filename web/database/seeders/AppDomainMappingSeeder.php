<?php

namespace Database\Seeders;

use App\Models\AppDomainMapping;
use Illuminate\Database\Seeder;

class AppDomainMappingSeeder extends Seeder
{
    private const MAPPINGS = [
        'com.google.android.youtube' => [
            'youtube.com', 'www.youtube.com', 'm.youtube.com',
            'youtubekids.com', 'www.youtubekids.com',
            'googlevideo.com', 'ytimg.com', 'youtu.be',
            'youtubei.googleapis.com', 'youtube.googleapis.com',
            'youtube-nocookie.com', 'www.youtube-nocookie.com',
        ],
        'org.telegram.messenger' => [
            'telegram.org', 't.me', 'telegram.me',
            'cdn-telegram.org', 'api.telegram.org',
            'graph.org', 'venus.web.telegram.org',
        ],
        'com.dazn' => [
            'dazn.com', 'www.dazn.com',
        ],
        'com.netflix.mediaclient' => [
            'netflix.com', 'www.netflix.com', 'nflxvideo.net',
            'nflximg.net', 'nflxext.com',
        ],
        'com.spotify.music' => [
            'spotify.com', 'www.spotify.com', 'scdn.co', 'spotifycdn.net',
        ],
        'com.amazon.avod.thirdpartyclient' => [
            'primevideo.com', 'www.primevideo.com',
        ],
        'br.com.band.bandplay' => [
            'bandplay.com.br', 'www.bandplay.com.br',
        ],
        'br.com.globo.globoplay' => [
            'globoplay.globo.com', 'globo.com', 'gstatics.com',
        ],
        'tv.pluto.android' => [
            'pluto.tv', 'plutotv.net',
        ],
        'de.zdf.androidapp' => [
            'zdf.de', 'www.zdf.de', 'zdfcloud.com',
        ],
        'ard.mediathek' => [
            'ardmediathek.de', 'www.ardmediathek.de', 'ard.de',
        ],
        'com.zattoo.player' => [
            'zattoo.com', 'www.zattoo.com',
        ],
    ];

    public function run(): void
    {
        foreach (self::MAPPINGS as $appPackage => $domains) {
            foreach ($domains as $domain) {
                AppDomainMapping::firstOrCreate(
                    ['app_package' => $appPackage, 'domain' => $domain],
                );
            }
        }
    }
}
