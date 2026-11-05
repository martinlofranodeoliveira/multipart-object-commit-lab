<?php
declare(strict_types=1);
namespace CommitLab;

final class Limits
{
    public const VERSION = '0.1.0';
    public const FILES = 4;
    public const FILE_BYTES = 2097152;
    public const BODY_BYTES = 9437184;
    public const ACTIVE = 4;
    public const BATCHES = 128;
    public const REFERENCES = 512;
    public const OBJECTS = 128;
    public const STAGES = 4;
    public const PAYLOAD_BYTES = 67108864;
    public const DB_PAGES = 2048;
    public const WAL_BYTES = 4194304;
    public const RESPONSE_BYTES = 2162688;
    public const DIAGNOSTIC_BYTES = 65536;
    public const DIAGNOSTIC_TOTAL = 262144;
}
