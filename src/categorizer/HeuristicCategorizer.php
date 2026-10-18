<?php

/**
 * Categorizador heurístico: asigna carpetas basándose en el dominio
 * y palabras clave del título. Rápido, gratuito, sin API.
 */
class HeuristicCategorizer {

    /** @var array<string,string> domain → folder learned from previously categorized bookmarks */
    private array $learnedRules;

    public function __construct(array $learnedRules = []) {
        $this->learnedRules = $learnedRules;
    }

    /**
     * Mapa de dominio (o parte del mismo) → carpeta sugerida.
     * Evaluado en orden; gana la primera coincidencia.
     */
    private const DOMAIN_RULES = [
        // Desarrollo
        'github.com'              => '/Dev/GitHub',
        'gitlab.com'              => '/Dev/GitLab',
        'stackoverflow.com'       => '/Dev/StackOverflow',
        'developer.mozilla.org'   => '/Dev/Documentación',
        'developer.android.com'   => '/Dev/Android',
        'developer.apple.com'     => '/Dev/Apple',
        'docs.python.org'         => '/Dev/Python',
        'docs.php.net'            => '/Dev/PHP',
        'npmjs.com'               => '/Dev/npm',
        'packagist.org'           => '/Dev/PHP',
        'codepen.io'              => '/Dev/Frontend',
        'jsfiddle.net'            => '/Dev/Frontend',
        'replit.com'              => '/Dev',
        'leetcode.com'            => '/Dev/Algoritmos',
        'hackerrank.com'          => '/Dev/Algoritmos',
        'devdocs.io'              => '/Dev/Documentación',
        'docker.com'              => '/Dev/DevOps',
        'kubernetes.io'           => '/Dev/DevOps',
        'digitalocean.com'        => '/Dev/DevOps',
        'heroku.com'              => '/Dev/DevOps',
        'vercel.com'              => '/Dev/DevOps',
        'netlify.com'             => '/Dev/DevOps',
        'aws.amazon.com'          => '/Dev/Cloud',
        'cloud.google.com'        => '/Dev/Cloud',
        'azure.microsoft.com'     => '/Dev/Cloud',
        'xda-developers.com'      => '/Dev/Android/XDA',
        'sourceforge.net'         => '/Dev',

        // Videos / Entretenimiento
        'youtube.com'             => '/Videos/YouTube',
        'youtu.be'                => '/Videos/YouTube',
        'twitch.tv'               => '/Videos/Twitch',
        'vimeo.com'               => '/Videos',
        'dailymotion.com'         => '/Videos',
        'bilibili.com'            => '/Videos',
        'odysee.com'              => '/Videos',
        'rumble.com'              => '/Videos',
        'netflix.com'             => '/Streaming/Netflix',
        'hulu.com'                => '/Streaming/Hulu',
        'primevideo.com'          => '/Streaming/Prime',
        'disneyplus.com'          => '/Streaming/Disney+',
        'hbomax.com'              => '/Streaming/HBO',
        'crunchyroll.com'         => '/Streaming/Anime',
        'funimation.com'          => '/Streaming/Anime',

        // Música
        'spotify.com'             => '/Música/Spotify',
        'music.youtube.com'       => '/Música/YouTube Music',
        'soundcloud.com'          => '/Música/SoundCloud',
        'bandcamp.com'            => '/Música',
        'last.fm'                 => '/Música',
        'deezer.com'              => '/Música/Deezer',
        'tidal.com'               => '/Música/Tidal',

        // Redes sociales
        'twitter.com'             => '/Social/Twitter',
        'x.com'                   => '/Social/Twitter',
        'facebook.com'            => '/Social/Facebook',
        'instagram.com'           => '/Social/Instagram',
        'linkedin.com'            => '/Social/LinkedIn',
        'reddit.com'              => '/Social/Reddit',
        'mastodon'                => '/Social/Mastodon',
        'discord.com'             => '/Social/Discord',
        'telegram.org'            => '/Social/Telegram',
        'whatsapp.com'            => '/Social/WhatsApp',
        'tiktok.com'              => '/Social/TikTok',
        'pinterest.com'           => '/Social/Pinterest',
        'tumblr.com'              => '/Social',

        // Noticias
        'bbc.com'                 => '/Noticias/BBC',
        'bbc.co.uk'               => '/Noticias/BBC',
        'cnn.com'                 => '/Noticias/CNN',
        'theguardian.com'         => '/Noticias/The Guardian',
        'nytimes.com'             => '/Noticias/NYT',
        'elpais.com'              => '/Noticias/El País',
        'elmundo.es'              => '/Noticias/El Mundo',
        'infobae.com'             => '/Noticias/Infobae',
        'clarin.com'              => '/Noticias',
        'lanacion.com.ar'         => '/Noticias',
        'hn.algolia.com'          => '/Noticias/Hacker News',
        'news.ycombinator.com'    => '/Noticias/Hacker News',
        'techmeme.com'            => '/Noticias/Tech',

        // Compras / Finanzas
        'amazon.com'              => '/Compras/Amazon',
        'amazon.com.ar'           => '/Compras/Amazon',
        'mercadolibre.com'        => '/Compras/MercadoLibre',
        'ebay.com'                => '/Compras/eBay',
        'aliexpress.com'          => '/Compras/AliExpress',
        'paypal.com'              => '/Finanzas/PayPal',
        'wise.com'                => '/Finanzas/Wise',
        'binance.com'             => '/Finanzas/Crypto',
        'coinbase.com'            => '/Finanzas/Crypto',

        // Herramientas
        'notion.so'               => '/Herramientas/Notas',
        'obsidian.md'             => '/Herramientas/Notas',
        'evernote.com'            => '/Herramientas/Notas',
        'trello.com'              => '/Herramientas/Productividad',
        'asana.com'               => '/Herramientas/Productividad',
        'jira.atlassian.com'      => '/Herramientas/Productividad',
        'figma.com'               => '/Herramientas/Diseño',
        'canva.com'               => '/Herramientas/Diseño',
        'dribbble.com'            => '/Herramientas/Diseño',
        'behance.net'             => '/Herramientas/Diseño',
        'excalidraw.com'          => '/Herramientas/Diseño',
        'draw.io'                 => '/Herramientas/Diseño',
        'diagrams.net'            => '/Herramientas/Diseño',
        'google.com/docs'         => '/Herramientas/Google',
        'docs.google.com'         => '/Herramientas/Google',
        'drive.google.com'        => '/Herramientas/Google',
        'sheets.google.com'       => '/Herramientas/Google',
        'slides.google.com'       => '/Herramientas/Google',
        'calendar.google.com'     => '/Herramientas/Google',
        'meet.google.com'         => '/Herramientas/Google',
        'translate.google.com'    => '/Herramientas/Traducción',
        'deepl.com'               => '/Herramientas/Traducción',

        // IA
        'chat.openai.com'         => '/IA/ChatGPT',
        'chatgpt.com'             => '/IA/ChatGPT',
        'claude.ai'               => '/IA/Claude',
        'anthropic.com'           => '/IA/Claude',
        'gemini.google.com'       => '/IA/Gemini',
        'bard.google.com'         => '/IA/Gemini',
        'huggingface.co'          => '/IA/Modelos',
        'replicate.com'           => '/IA/Modelos',
        'stability.ai'            => '/IA/Imágenes',
        'midjourney.com'          => '/IA/Imágenes',
        'perplexity.ai'           => '/IA/Búsqueda',
        'copilot.microsoft.com'   => '/IA/Copilot',

        // Referencia / Wikipedia
        'wikipedia.org'           => '/Referencia/Wikipedia',
        'wikimedia.org'           => '/Referencia/Wikipedia',
        'wolframalpha.com'        => '/Referencia',
        'archive.org'             => '/Referencia/Archive',

        // Juegos
        'steampowered.com'        => '/Juegos/Steam',
        'store.steampowered.com'  => '/Juegos/Steam',
        'epicgames.com'           => '/Juegos/Epic',
        'itch.io'                 => '/Juegos/Indie',
        'gog.com'                 => '/Juegos/GOG',
        'xbox.com'                => '/Juegos/Xbox',
        'playstation.com'         => '/Juegos/PlayStation',
        'nintendo.com'            => '/Juegos/Nintendo',
        'humblebundle.com'        => '/Juegos',
        'pcgamingwiki.com'        => '/Juegos/Wiki',

        // Anime / Manga
        'myanimelist.net'         => '/Anime/MyAnimeList',
        'anilist.co'              => '/Anime/AniList',

        // Linux / Open Source
        'kernel.org'              => '/Linux',
        'archlinux.org'           => '/Linux/Arch',
        'ubuntu.com'              => '/Linux/Ubuntu',
        'debian.org'              => '/Linux/Debian',
        'fedoraproject.org'       => '/Linux',
        'opensuse.org'            => '/Linux',
        'launchpad.net'           => '/Linux/Ubuntu',

        // Email / Comunicación
        'gmail.com'               => '/Comunicación/Gmail',
        'mail.google.com'         => '/Comunicación/Gmail',
        'outlook.com'             => '/Comunicación/Outlook',
        'protonmail.com'          => '/Comunicación/ProtonMail',
        'tutanota.com'            => '/Comunicación',
        'zoom.us'                 => '/Comunicación/Reuniones',
        'teams.microsoft.com'     => '/Comunicación/Reuniones',

        // Localhost / LAN
        'localhost'               => '/Localhost',
        'raspberrypi.local'       => '/Localhost/Raspberry Pi',
        '192.168.'                => '/Localhost',
        '10.0.'                   => '/Localhost',
    ];

    /**
     * Palabras clave en el título (lowercase) → carpeta sugerida.
     * Solo se aplican si no hubo match de dominio.
     */
    private const TITLE_RULES = [
        'tutorial'        => '/Dev/Tutoriales',
        'documentation'   => '/Dev/Documentación',
        'documentación'   => '/Dev/Documentación',
        'how to'          => '/Dev/Tutoriales',
        'cheatsheet'      => '/Dev/Referencia',
        'cheat sheet'     => '/Dev/Referencia',
        'roadmap'         => '/Dev/Aprendizaje',
        'learn'           => '/Dev/Aprendizaje',
        'course'          => '/Dev/Aprendizaje',
        'curso'           => '/Dev/Aprendizaje',
        'anime'           => '/Anime',
        'manga'           => '/Anime/Manga',
        'recipe'          => '/Recetas',
        'receta'          => '/Recetas',
        'torrent'         => '/Descargas/Torrents',
        'download'        => '/Descargas',
        'descargar'       => '/Descargas',
        'rom '            => '/Dev/Android/ROMs',
        ' rom'            => '/Dev/Android/ROMs',
    ];

    /**
     * @param array[] $bookmarks  Filas de BD (con keys url, title, folder_path, source)
     * @return array[]  Mismas filas + clave 'suggested_folder' y 'confidence'
     */
    public function categorize(array $bookmarks): array {
        $results = [];

        foreach ($bookmarks as $bm) {
            $url   = $bm['url']   ?? '';
            $title = $bm['title'] ?? '';

            [$suggested, $confidence] = $this->suggest($url, $title);

            $results[] = array_merge($bm, [
                'suggested_folder' => $suggested,
                'confidence'       => $confidence,
                'engine'           => 'heuristic',
            ]);
        }

        return $results;
    }

    private function suggest(string $url, string $title): array {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
        // Remove www.
        $host = preg_replace('/^www\./', '', $host);

        // 0. Learned rules from previously categorized bookmarks
        if ($host && isset($this->learnedRules[$host])) {
            return [$this->learnedRules[$host], 0.92];
        }

        // 1. Domain match
        foreach (self::DOMAIN_RULES as $pattern => $folder) {
            if (str_contains($host, $pattern) || str_contains($url, $pattern)) {
                return [$folder, 0.9];
            }
        }

        // 2. Title keyword match
        $titleLower = strtolower($title);
        foreach (self::TITLE_RULES as $keyword => $folder) {
            if (str_contains($titleLower, $keyword)) {
                return [$folder, 0.6];
            }
        }

        // 3. TLD / generic domain hints
        if (str_ends_with($host, '.gov') || str_ends_with($host, '.gob')) {
            return ['/Gobierno', 0.5];
        }
        if (str_ends_with($host, '.edu') || str_ends_with($host, '.edu.ar')) {
            return ['/Educación', 0.5];
        }

        return ['/Sin categorizar', 0.0];
    }
}
