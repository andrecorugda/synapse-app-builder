<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Process\Process;

class CodeLintController
{
    /**
     * Lint a PHP snippet with `php -l` (syntax check ONLY — it parses, it does
     * not execute the code) and return error markers for the editor.
     */
    public function php(Request $request): JsonResponse
    {
        $code = (string) $request->input('code', '');

        if (trim($code) === '') {
            return response()->json(['errors' => []]);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'pb_lint_').'.php';
        // Prepend an opening tag on its own line; reported line numbers are then
        // offset by 1 from the user's code (corrected below).
        file_put_contents($tmp, "<?php\n".$code);

        try {
            $process = new Process([PHP_BINARY, '-l', $tmp]);
            $process->run();
            $output = $process->getErrorOutput()."\n".$process->getOutput();
        } finally {
            @unlink($tmp);
        }

        $errors = [];
        // e.g. "Parse error: syntax error, unexpected ... in /tmp/x.php on line 4"
        if (preg_match('/^(Parse error|Fatal error):\s*(.+?) in .+ on line (\d+)/mi', $output, $m)) {
            $errors[] = [
                'message' => trim($m[2]),
                'line' => max(1, ((int) $m[3]) - 1),
            ];
        }

        return response()->json(['errors' => $errors]);
    }
}
