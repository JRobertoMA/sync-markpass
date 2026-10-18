<?php

class Bookmark {
    public string $url;
    public string $title;
    public string $folderPath;
    public ?int   $addDate;
    public ?int   $lastModified;
    public ?string $icon;
    public string $source; // 'chrome' | 'firefox' | 'both'

    public function __construct(
        string  $url,
        string  $title       = '',
        string  $folderPath  = '/',
        ?int    $addDate     = null,
        ?int    $lastModified = null,
        ?string $icon        = null,
        string  $source      = 'chrome'
    ) {
        $this->url          = $url;
        $this->title        = $title;
        $this->folderPath   = $folderPath;
        $this->addDate      = $addDate;
        $this->lastModified = $lastModified;
        $this->icon         = $icon;
        $this->source       = $source;
    }

    public function toArray(): array {
        return [
            'url'           => $this->url,
            'title'         => $this->title,
            'folder_path'   => $this->folderPath,
            'add_date'      => $this->addDate,
            'last_modified' => $this->lastModified,
            'icon'          => $this->icon,
            'source'        => $this->source,
        ];
    }

    public static function fromArray(array $data): self {
        return new self(
            $data['url']           ?? '',
            $data['title']         ?? '',
            $data['folder_path']   ?? '/',
            isset($data['add_date'])      ? (int)$data['add_date']      : null,
            isset($data['last_modified']) ? (int)$data['last_modified'] : null,
            $data['icon']          ?? null,
            $data['source']        ?? 'chrome'
        );
    }
}
