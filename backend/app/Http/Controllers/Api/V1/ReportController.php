<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportRequest;
use App\Services\DateRange;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(private ReportService $service) {}

    public function show(ReportRequest $request): JsonResponse
    {
        return response()->json(['success' => true, 'message' => 'Report generated', 'data' => $this->report($request)])->header('Cache-Control', 'no-store, private');
    }

    public function export(ReportRequest $request): StreamedResponse
    {
        $report = $this->report($request);
        $filters = $report['filters'];
        $filename = 'smartbiz-'.$report['report'].'-'.$filters['start_date'].'-'.$filters['end_date'].'.csv';

        return response()->streamDownload(function () use ($report, $filters): void {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            $rows = [[$report['title']], [$report['description']], ['Start date', $filters['start_date'], 'End date', $filters['end_date']], ['Timezone', $filters['timezone'], 'Currency', $filters['currency']], ['Generated at', $filters['generated_at']], [], $report['columns'], ...$report['rows']];
            foreach ($rows as $row) {
                fputcsv($stream, array_map($this->service->csvCell(...), $row), ',', '"', '', "\r\n");
            }
            fclose($stream);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function report(ReportRequest $request): array
    {
        $input = $request->validated();

        return $this->service->generate($request->user(), DateRange::from($input), $input['report']);
    }
}
