<?php

class BookmarkFolder {
    public string $name;
    public string $path;
    public string $source;
    /** @var BookmarkFolder[] */
    public array $subfolders = [];
    /** @var Bookmark[] */
    public array $bookmarks = [];

    public function __construct(string $name, string $path, string $source) {
        $this->name   = $name;
        $this->path   = $path;
        $this->source = $source;
    }

    public function addBookmark(Bookmark $bookmark): void {
        $this->bookmarks[] = $bookmark;
    }

    public function addSubfolder(BookmarkFolder $folder): void {
        $this->subfolders[] = $folder;
    }

    public function toArray(): array {
        return [
            'name'       => $this->name,
            'path'       => $this->path,
            'source'     => $this->source,
            'subfolders' => array_map(fn($f) => $f->toArray(), $this->subfolders),
            'bookmarks'  => array_map(fn($b) => $b->toArray(), $this->bookmarks),
        ];
    }
}

class BookmarkTree {
    /** @var BookmarkFolder[] */
    private array $roots = [];

    public function addRoot(BookmarkFolder $folder): void {
        $this->roots[] = $folder;
    }

    /** @return BookmarkFolder[] */
    public function getRoots(): array {
        return $this->roots;
    }

    /** @return Bookmark[] */
    public function flattenBookmarks(): array {
        $all = [];
        foreach ($this->roots as $root) {
            $this->collectBookmarks($root, $all);
        }
        return $all;
    }

    /**
     * Returns all BookmarkFolder objects in the tree, each with an added
     * 'parent_path' key (null for root-level folders).
     *
     * @return array{folder: BookmarkFolder, parent_path: string|null}[]
     */
    public function flattenFolders(): array {
        $all = [];
        foreach ($this->roots as $root) {
            $this->collectFolders($root, $all, null);
        }
        return $all;
    }

    private function collectBookmarks(BookmarkFolder $folder, array &$out): void {
        foreach ($folder->bookmarks as $b) {
            $out[] = $b;
        }
        foreach ($folder->subfolders as $sub) {
            $this->collectBookmarks($sub, $out);
        }
    }

    private function collectFolders(BookmarkFolder $folder, array &$out, ?string $parentPath): void {
        $out[] = ['folder' => $folder, 'parent_path' => $parentPath];
        foreach ($folder->subfolders as $sub) {
            $this->collectFolders($sub, $out, $folder->path);
        }
    }

    public function toArray(): array {
        return array_map(fn($r) => $r->toArray(), $this->roots);
    }
}
