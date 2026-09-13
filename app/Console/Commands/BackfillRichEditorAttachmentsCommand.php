<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Upload\BackfillRichEditorAttachments;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Move legacy rich editor images from bare public-disk paths into media rows on their records')]
#[Signature('media:backfill-rich-editor-attachments {--force : Write changes instead of reporting them}')]
final class BackfillRichEditorAttachmentsCommand extends Command
{
    public function handle(BackfillRichEditorAttachments $backfill): int
    {
        $force = (bool) $this->option('force');

        $report = $backfill->execute(write: $force);

        foreach ($report['skipped'] as $line) {
            $this->warn($line);
        }

        foreach ($report['migrated'] as $line) {
            $this->info($line);
        }

        $count = count($report['migrated']);

        $this->comment($force
            ? "{$count} image(s) migrated."
            : "{$count} image(s) would be migrated. Re-run with --force to write.");

        return self::SUCCESS;
    }
}
