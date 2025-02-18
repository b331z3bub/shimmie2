<?php

declare(strict_types=1);

namespace Shimmie2;

require_once "config.php";

/**
 * Occurs when some data is being uploaded.
 */
class DataUploadEvent extends Event
{
    public string $hash;
    public string $mime;
    public int $size;

    /** @var Image[] */
    public array $images = [];
    public bool $handled = false;
    public bool $merged = false;

    /**
     * Some data is being uploaded.
     * This should be caught by a file handler.
     *
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $tmpname,
        public array $metadata,
    ) {
        parent::__construct();

        $this->set_tmpname($tmpname);
        assert(is_string($metadata["filename"]));
        assert(is_array($metadata["tags"]));
        assert(is_string($metadata["source"]) || is_null($metadata["source"]));

        // DB limits to 255 char filenames
        $metadata['filename'] = substr($metadata['filename'], 0, 255);
    }

    public function set_tmpname(string $tmpname, ?string $mime = null): void
    {
        assert(is_readable($tmpname));
        $this->tmpname = $tmpname;
        $this->hash = md5_file_ex($tmpname);
        $this->size = filesize_ex($tmpname);
        $mime = $mime ?? MimeType::get_for_file($tmpname, get_file_ext($this->metadata["filename"]) ?? null);
        if (empty($mime)) {
            throw new UploadException("Could not determine mime type");
        }

        $this->mime = strtolower($mime);
    }
}

class UploadException extends SCoreException
{
}

abstract class UploadResult
{
    public function __construct(
        public string $name
    ) {
    }
}

class UploadError extends UploadResult
{
    public function __construct(
        string $name,
        public string $error
    ) {
        parent::__construct($name);
    }
}

class UploadSuccess extends UploadResult
{
    public function __construct(
        string $name,
        public int $image_id
    ) {
        parent::__construct($name);
    }
}

/**
 * Main upload class.
 * All files that are uploaded to the site are handled through this class.
 * This also includes transloaded files as well.
 */
class Upload extends Extension
{
    /** @var UploadTheme */
    protected Themelet $theme;
    public bool $is_full;

    /**
     * Early, so it can stop the DataUploadEvent before any data handlers see it.
     */
    public function get_priority(): int
    {
        return 40;
    }

    public function onInitExt(InitExtEvent $event): void
    {
        global $config;
        $config->set_default_int(UploadConfig::COUNT, 3);
        $config->set_default_int(UploadConfig::SIZE, parse_shorthand_int('1MB'));
        $config->set_default_int(UploadConfig::MIN_FREE_SPACE, parse_shorthand_int('100MB'));
        $config->set_default_bool(UploadConfig::TLSOURCE, true);

        $this->is_full = false;

        $min_free_space = $config->get_int(UploadConfig::MIN_FREE_SPACE);
        if ($min_free_space > 0) {
            // SHIT: fucking PHP "security" measures -_-;;;
            $img_path = realpath("./images/");
            if ($img_path) {
                $free_num = @disk_free_space($img_path);
                if ($free_num !== false) {
                    $this->is_full = $free_num < $min_free_space;
                }
            }
        }

        $config->set_default_bool(UploadConfig::MIME_CHECK_ENABLED, false);
        $config->set_default_array(
            UploadConfig::ALLOWED_MIME_STRINGS,
            DataHandlerExtension::get_all_supported_mimes()
        );
    }

    public function onSetupBuilding(SetupBuildingEvent $event): void
    {
        $tes = [];
        $tes["Disabled"] = "none";
        if (function_exists("curl_init")) {
            $tes["cURL"] = "curl";
        }
        $tes["fopen"] = "fopen";
        $tes["WGet"] = "wget";

        $sb = $event->panel->create_new_block("Upload");
        $sb->position = 10;
        // Output the limits from PHP so the user has an idea of what they can set.
        $sb->add_int_option(UploadConfig::COUNT, "Max uploads: ");
        $sb->add_label("<i>PHP Limit = " . ini_get('max_file_uploads') . "</i>");
        $sb->add_shorthand_int_option(UploadConfig::SIZE, "<br/>Max size per file: ");
        $sb->add_label("<i>PHP Limit = " . ini_get('upload_max_filesize') . "</i>");
        $sb->add_choice_option(UploadConfig::TRANSLOAD_ENGINE, $tes, "<br/>Transload: ");
        $sb->add_bool_option(UploadConfig::TLSOURCE, "<br/>Use transloaded URL as source if none is provided: ");

        $sb->start_table();
        $sb->add_bool_option(UploadConfig::MIME_CHECK_ENABLED, "Enable upload MIME checks", true);
        $sb->add_multichoice_option(UploadConfig::ALLOWED_MIME_STRINGS, $this->get_mime_options(), "Allowed MIME uploads", true);
        $sb->end_table();
    }

    /**
     * @return array<string, string>
     */
    private function get_mime_options(): array
    {
        $output = [];
        foreach (DataHandlerExtension::get_all_supported_mimes() as $mime) {
            $output[MimeMap::get_name_for_mime($mime)] = $mime;
        }
        return $output;
    }

    public function onPageNavBuilding(PageNavBuildingEvent $event): void
    {
        global $user;
        if ($user->can(Permissions::CREATE_IMAGE)) {
            $event->add_nav_link("upload", new Link('upload'), "Upload");
        }
    }

    public function onPageSubNavBuilding(PageSubNavBuildingEvent $event): void
    {
        if ($event->parent == "upload") {
            if (Extension::is_enabled(WikiInfo::KEY)) {
                $event->add_nav_link("upload_guidelines", new Link('wiki/upload_guidelines'), "Guidelines");
            }
        }
    }

    public function onDataUpload(DataUploadEvent $event): void
    {
        global $config;
        if ($this->is_full) {
            throw new UploadException("Upload failed; disk nearly full");
        }
        if ($event->size > $config->get_int(UploadConfig::SIZE)) {
            $size = to_shorthand_int($event->size);
            $limit = to_shorthand_int($config->get_int(UploadConfig::SIZE));
            throw new UploadException("File too large ($size > $limit)");
        }
    }

    public function onPageRequest(PageRequestEvent $event): void
    {
        global $cache, $page, $user;

        if ($user->can(Permissions::CREATE_IMAGE)) {
            if ($this->is_full) {
                $this->theme->display_full($page);
            } else {
                $this->theme->display_block($page);
            }
        }

        if ($event->page_matches("upload")) {
            if (!$user->can(Permissions::CREATE_IMAGE)) {
                $this->theme->display_error(403, "Error", "{$user->name} doesn't have permission to upload images");
                return;
            }
            if ($this->is_full) {
                $this->theme->display_error(507, "Error", "Can't upload images: disk nearly full");
                return;
            }

            if($event->method == "GET") {
                $this->theme->display_page($page);
            } elseif($event->method == "POST") {
                $results = [];

                $files = array_filter($_FILES, function ($file) {
                    return !empty($file['name']);
                });
                foreach ($files as $name => $file) {
                    $slot = int_escape(substr($name, 4));
                    $tags = $this->tags_for_upload_slot($event->POST, $slot);
                    $source = $this->source_for_upload_slot($event->POST, $slot);
                    $results = array_merge($results, $this->try_upload($file, $tags, $source));
                }

                $urls = array_filter($event->POST, function ($value, $key) {
                    return str_starts_with($key, "url") && is_string($value) && strlen($value) > 0;
                }, ARRAY_FILTER_USE_BOTH);
                foreach ($urls as $name => $value) {
                    $slot = int_escape(substr($name, 3));
                    $tags = $this->tags_for_upload_slot($event->POST, $slot);
                    $source = $this->source_for_upload_slot($event->POST, $slot);
                    $results = array_merge($results, $this->try_transload($value, $tags, $source));
                }

                $this->theme->display_upload_status($page, $results);
            }
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return string[]
     */
    private function tags_for_upload_slot(array $params, int $id): array
    {
        # merge then explode, not explode then merge - else
        # one of the merges may create a surplus "tagme"
        return Tag::explode(
            ($params["tags"] ?? "") .
            " " .
            ($params["tags$id"] ?? "")
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    private function source_for_upload_slot(array $params, int $id): ?string
    {
        global $config;
        if(!empty($params["source$id"])) {
            return $params["source$id"];
        }
        if(!empty($params['source'])) {
            return $params['source'];
        }
        if($config->get_bool(UploadConfig::TLSOURCE) && !empty($params["url$id"])) {
            return $params["url$id"];
        }
        return null;
    }

    /**
     * Returns a descriptive error message for the specified PHP error code.
     *
     * This is a helper function based on the one from the online PHP Documentation
     * which is licensed under Creative Commons Attribution 3.0 License
     *
     * TODO: Make these messages user/admin editable
     */
    private function upload_error_message(int $error_code): string
    {
        switch ($error_code) {
            case UPLOAD_ERR_INI_SIZE:
                return 'The uploaded file exceeds the upload_max_filesize directive in php.ini';
            case UPLOAD_ERR_FORM_SIZE:
                return 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form';
            case UPLOAD_ERR_PARTIAL:
                return 'The uploaded file was only partially uploaded';
            case UPLOAD_ERR_NO_FILE:
                return 'No file was uploaded';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Missing a temporary folder';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Failed to write file to disk';
            case UPLOAD_ERR_EXTENSION:
                return 'File upload stopped by extension';
            default:
                return 'Unknown upload error';
        }
    }

    /**
     * Handle an upload.
     * @param mixed[] $file
     * @param string[] $tags
     * @return UploadResult[]
     */
    private function try_upload(array $file, array $tags, ?string $source = null): array
    {
        global $page, $config, $database;

        // blank file boxes cause empty uploads, no need for error message
        if (empty($file['name'])) {
            return [];
        }

        if (empty($source)) {
            $source = null;
        }

        $results = [];

        for ($i = 0; $i < count($file['name']); $i++) {
            $name = $file['name'][$i];
            $error = $file['error'][$i];
            $tmp_name = $file['tmp_name'][$i];

            if (empty($name)) {
                continue;
            }
            try {
                // check if the upload was successful
                if ($error !== UPLOAD_ERR_OK) {
                    throw new UploadException($this->upload_error_message($error));
                }

                $new_images = $database->with_savepoint(function () use ($tmp_name, $name, $tags, $source) {
                    $event = send_event(new DataUploadEvent($tmp_name, [
                        'filename' => pathinfo($name, PATHINFO_BASENAME),
                        'tags' => $tags,
                        'source' => $source,
                    ]));
                    if (count($event->images) == 0) {
                        throw new UploadException("MIME type not supported: " . $event->mime);
                    }
                    return $event->images;
                });
                foreach($new_images as $image) {
                    $results[] = new UploadSuccess($name, $image->id);
                }
            } catch (UploadException $ex) {
                $results[] = new UploadError($name, $ex->getMessage());
            }
        }

        return $results;
    }

    /**
     * @param string[] $tags
     * @return UploadResult[]
     */
    private function try_transload(string $url, array $tags, string $source = null): array
    {
        global $page, $config, $user, $database;

        $results = [];
        $tmp_filename = shm_tempnam("transload");

        try {
            // Fetch file
            try {
                $headers = fetch_url($url, $tmp_filename);
            } catch (FetchException $e) {
                throw new UploadException("Error reading from $url: $e");
            }

            // Parse metadata
            $s_filename = find_header($headers, 'Content-Disposition');
            $h_filename = ($s_filename ? preg_replace('/^.*filename="([^ ]+)"/i', '$1', $s_filename) : null);
            $filename = $h_filename ?: basename($url);

            $metadata = [];
            $metadata['filename'] = $filename;
            $metadata['tags'] = $tags;
            $metadata['source'] = $source;

            $new_images = $database->with_savepoint(function () use ($tmp_filename, $metadata) {
                $event = send_event(new DataUploadEvent($tmp_filename, $metadata));
                if (count($event->images) == 0) {
                    throw new UploadException("File type not supported: " . $event->mime);
                }
                return $event->images;
            });
            foreach($new_images as $image) {
                $results[] = new UploadSuccess($url, $image->id);
            }
        } catch (UploadException $ex) {
            $results[] = new UploadError($url, $ex->getMessage());
        } finally {
            if (file_exists($tmp_filename)) {
                unlink($tmp_filename);
            }
        }

        return $results;
    }
}
