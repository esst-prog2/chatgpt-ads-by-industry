<?php

namespace App\Http\Controllers;

use App\Services\Aggregator;
use App\Services\DashboardService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DashboardController extends Controller
{
    public function index(Request $request, DashboardService $dashboard)
    {
        $end = CarbonImmutable::yesterday()->toDateString();
        $default = ['from' => CarbonImmutable::yesterday()->subDays(27)->toDateString(), 'to' => $end];

        $validator = Validator::make($request->query(), [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'metric' => ['nullable', 'in:'.implode(',', Aggregator::METRICS)],
        ]);

        $errors = [];
        if ($validator->fails()) {
            $errors = $validator->errors()->all();
            $range = $request->session()->get('last_range', $default);
            $metric = $request->session()->get('last_metric', 'impressions');
        } else {
            $range = [
                'from' => $request->query('from', $default['from']),
                'to' => $request->query('to', $default['to']),
            ];
            $metric = $request->query('metric', 'impressions');
            $request->session()->put('last_range', $range);
            $request->session()->put('last_metric', $metric);
        }

        $data = $dashboard->build($range['from'], $range['to'], $metric, $request->query('industry'));

        return view('dashboard', $data + ['errors_list' => $errors, 'metrics' => Aggregator::METRICS]);
    }
}
