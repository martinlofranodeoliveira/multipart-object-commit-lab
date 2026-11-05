<?php
declare(strict_types=1);
namespace CommitLab;

final class Admission
{
    public static function labels(string $json, int $count): array
    {
        if (strlen($json) > 4096) {
            throw new Failure(422, 'invalid_labels', 'Labels JSON is too large.');
        }
        try {
            $labels = json_decode($json, false, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new Failure(422, 'invalid_labels', 'Labels must be a UTF-8 JSON list.');
        }
        if (!is_array($labels)) {
            throw new Failure(422, 'invalid_labels', 'Provide one through four labels matching the files.');
        }
        return self::validateLabels($labels, $count);
    }

    public static function validateLabels(array $labels, int $count): array
    {
        if (!array_is_list($labels) || count($labels) !== $count || $count < 1 || $count > Limits::FILES) {
            throw new Failure(422, 'invalid_labels', 'Provide one through four labels matching the files.');
        }
        $seen = [];
        foreach ($labels as $label) {
            if (!is_string($label) || $label === '' || strlen($label) > 256 || str_contains($label, "\0") || preg_match('//u', $label) !== 1) {
                throw new Failure(422, 'invalid_labels', 'Each label must contain 1 through 256 UTF-8 bytes without NUL.');
            }
            $key = bin2hex($label);
            if (isset($seen[$key])) {
                throw new Failure(422, 'invalid_labels', 'Labels must be bytewise unique within a batch.');
            }
            $seen[$key] = true;
        }
        return $labels;
    }

    public static function fromPhp(): array
    {
        $length = $_SERVER['CONTENT_LENGTH'] ?? '';
        if (!is_string($length) || $length === '' || strlen($length) > 7 || !ctype_digit($length) || (int) $length > Limits::BODY_BYTES) {
            throw new Failure(413, 'body_limit', 'Request body exceeds the admitted limit or has no length.');
        }
        if (!preg_match('~\Amultipart/form-data\s*;~i', $_SERVER['CONTENT_TYPE'] ?? '')) {
            throw new Failure(415, 'multipart_required', 'Use multipart/form-data.');
        }
        if (!function_exists('request_parse_body') || filter_var(ini_get('enable_post_data_reading'), FILTER_VALIDATE_BOOL)) {
            throw new Failure(503, 'parser_configuration', 'Use PHP 8.4 explicit parsing with automatic body parsing disabled.');
        }
        // Unlike automatic POST parsing, the explicit maintained parser throws
        // on its count/size limits. No php://input read may precede this call.
        set_error_handler(static function (int $severity): never {
            throw new \ErrorException('Multipart parser warning', 0, $severity);
        }, E_WARNING | E_NOTICE);
        try {
            [$post, $postedFiles] = request_parse_body([
                'max_file_uploads' => Limits::FILES,
                'max_input_vars' => 1,
                'max_multipart_body_parts' => Limits::FILES + 1,
                'post_max_size' => Limits::BODY_BYTES,
                'upload_max_filesize' => Limits::FILE_BYTES,
            ]);
        } catch (\RequestParseBodyException | \ErrorException) {
            throw new Failure(422, 'invalid_upload', 'Multipart parsing failed or exceeded its admitted limits.');
        } finally {
            restore_error_handler();
        }
        if (array_keys($post) !== ['labels'] || !is_string($post['labels']) || array_keys($postedFiles) !== ['files']) {
            throw new Failure(422, 'invalid_upload', 'Use only the labels field and files[] parts.');
        }
        $upload = $postedFiles['files'];
        $keys = array_keys($upload);
        sort($keys);
        $expected = ['error', 'name', 'size', 'tmp_name', 'type'];
        if (array_key_exists('full_path', $upload)) {
            $expected[] = 'full_path';
        }
        sort($expected);
        if ($keys !== $expected) {
            throw new Failure(422, 'invalid_upload', 'Unexpected PHP upload shape.');
        }
        foreach ($upload as $column) {
            if (!is_array($column) || !array_is_list($column)) {
                throw new Failure(422, 'invalid_upload', 'Use a flat files[] list.');
            }
        }
        $count = count($upload['error']);
        if ($count < 1 || $count > Limits::FILES) {
            throw new Failure(422, 'invalid_upload', 'A batch contains one through four files.');
        }
        foreach ($upload as $column) {
            if (count($column) !== $count) {
                throw new Failure(422, 'invalid_upload', 'Incomplete upload arrays.');
            }
        }
        $labels = self::labels($post['labels'], $count);
        $admitted = [];
        for ($i = 0; $i < $count; $i++) {
            $path = $upload['tmp_name'][$i];
            $size = $upload['size'][$i];
            if ($upload['error'][$i] !== UPLOAD_ERR_OK || !is_int($size) || $size < 0 || $size > Limits::FILE_BYTES || !is_string($path) || !is_uploaded_file($path)) {
                throw new Failure(422, 'invalid_upload', 'Every file must be a complete upload of at most 2 MiB.');
            }
            // Names, MIME hints and full_path values are deliberately not used.
            foreach (['name', 'type'] as $column) {
                if (!is_string($upload[$column][$i])) {
                    throw new Failure(422, 'invalid_upload', 'Invalid upload metadata.');
                }
            }
            $stream = fopen($path, 'rb');
            if ($stream === false) {
                throw new Failure(503, 'storage_unavailable', 'Cannot read parser temporary file.');
            }
            try {
                [$digest, $actual] = self::transfer($stream, null);
            } finally {
                fclose($stream);
            }
            if ($actual !== $size) {
                throw new Failure(422, 'invalid_upload', 'Uploaded size did not match the complete file.');
            }
            $admitted[] = ['label' => $labels[$i], 'path' => $path, 'digest' => $digest, 'size' => $size];
        }
        return $admitted;
    }

    public static function fromArray(array $files): array
    {
        $count = count($files);
        if (!array_is_list($files) || $count < 1 || $count > Limits::FILES) {
            throw new Failure(422, 'invalid_upload', 'A batch contains one through four files.');
        }
        $rawLabels = [];
        foreach ($files as $entry) {
            if (!is_array($entry) || !array_key_exists('label', $entry)) {
                throw new Failure(422, 'invalid_labels', 'Provide one through four labels matching the files.');
            }
            $rawLabels[] = $entry['label'];
        }
        $labels = self::validateLabels($rawLabels, $count);
        $admitted = [];
        foreach ($files as $index => $entry) {
            $hasPath = array_key_exists('path', $entry);
            $hasBytes = array_key_exists('bytes', $entry);
            if ($hasPath === $hasBytes) {
                throw new Failure(422, 'invalid_upload', 'Each file entry requires exactly one of path or bytes.');
            }
            if ($hasBytes) {
                $bytes = $entry['bytes'];
                if (!is_string($bytes) || strlen($bytes) > Limits::FILE_BYTES) {
                    throw new Failure(422, 'invalid_upload', 'Every file must be a complete upload of at most 2 MiB.');
                }
                $admitted[] = [
                    'label' => $labels[$index],
                    'bytes' => $bytes,
                    'digest' => hash('sha256', $bytes),
                    'size' => strlen($bytes),
                ];
                continue;
            }
            $path = $entry['path'];
            if (!is_string($path) || $path === '' || strlen($path) > 4096 || str_contains($path, "\0")) {
                throw new Failure(422, 'invalid_upload', 'Every file must be a complete upload of at most 2 MiB.');
            }
            $s = NamespaceStore::regular($path);
            if ($s['size'] < 0 || $s['size'] > Limits::FILE_BYTES) {
                throw new Failure(422, 'invalid_upload', 'Every file must be a complete upload of at most 2 MiB.');
            }
            $stream = fopen($path, 'rb');
            if ($stream === false) {
                throw new Failure(503, 'storage_unavailable', 'Cannot read input file.');
            }
            try {
                [$digest, $actual] = self::transfer($stream, null);
            } finally {
                fclose($stream);
            }
            if ($actual !== $s['size']) {
                throw new Failure(422, 'invalid_upload', 'Uploaded size did not match the complete file.');
            }
            $admitted[] = [
                'label' => $labels[$index],
                'path' => $path,
                'digest' => $digest,
                'size' => $actual,
            ];
        }
        return $admitted;
    }

    public static function transfer(mixed $input, mixed $output): array
    {
        $hash = hash_init('sha256');
        $size = 0;
        while (!feof($input)) {
            $bytes = fread($input, 16384);
            if ($bytes === false || ($bytes === '' && !feof($input))) {
                throw new Failure(503, 'storage_unavailable', 'Stream read failed.');
            }
            $size += strlen($bytes);
            if ($size > Limits::FILE_BYTES) {
                throw new Failure(422, 'invalid_upload', 'A file exceeds 2 MiB.');
            }
            hash_update($hash, $bytes);
            if ($output !== null) {
                $offset = 0;
                while ($offset < strlen($bytes)) {
                    $written = fwrite($output, substr($bytes, $offset));
                    if ($written === false || $written === 0) {
                        throw new Failure(503, 'storage_unavailable', 'Staging write failed.');
                    }
                    $offset += $written;
                }
            }
        }
        return [hash_final($hash), $size];
    }
}
