<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $data = $this->catalog();

        Schema::create('qa_rules', function (Blueprint $table): void {
            $table->string('id', 96)->primary();
            $table->string('sector', 32);
            $table->string('scope', 16);
            $table->string('severity', 8);
            $table->string('mode', 16);
            $table->string('title');
            $table->text('check');
            $table->text('remediation');
            $table->index(['sector', 'id']);
        });

        foreach (array_chunk($data, 100) as $batch) {
            DB::table('qa_rules')->insert($batch);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('qa_rules');
    }

    /** @return list<array<string, string>> */
    private function catalog(): array
    {
        $fields = ['id', 'sector', 'scope', 'severity', 'title', 'check', 'remediation'];
        $file = new SplFileObject(resource_path('qa-rules-v1.csv'));
        $file->setCsvControl(escape: '');
        if ($file->fgetcsv() !== $fields) {
            throw new RuntimeException('The QA catalog has an invalid CSV header.');
        }

        $rules = [];
        while (! $file->eof()) {
            $row = $file->fgetcsv();
            if ($row === [null] || $row === false) {
                continue;
            }
            if (count($row) !== count($fields) || in_array('', $row, true) || in_array(null, $row, true) || isset($rules[$row[0]])) {
                throw new RuntimeException('The QA catalog contains an incomplete or duplicate rule.');
            }

            $rules[$row[0]] = [...array_combine($fields, $row), 'mode' => 'guidance'];
        }

        return array_values($rules);
    }
};
