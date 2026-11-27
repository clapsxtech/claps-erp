<?php
namespace App\Infrastructure\Cloudflare;

/** A single transaction has more writes than one D1 commit accepts. Nothing was sent or saved. */
class TransactionTooLarge extends \RuntimeException {}
