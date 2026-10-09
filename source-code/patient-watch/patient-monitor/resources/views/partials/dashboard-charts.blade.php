<div class="section-heading analytics-heading">
    <div><h2>Monitoring activity</h2><span>{{ auth()->user()->isAdmin() ? 'All monitored patients' : 'Your assigned patients' }}</span></div>
    <span>{{ $chartPeriod }} &middot; Philippine time</span>
</div>
@php
    $possibleTotal = $activityChart->sum('possible_fall');
    $manualTotal = $activityChart->sum('manual_emergency');
    $peakDay = $activityChart->sortByDesc('total')->first();
    $activityMidScale = max(1, (int) ceil($activityMax / 2));
    $plotLeft = 50;
    $plotRight = 650;
    $plotTop = 12;
    $plotBaseline = 158;
    $plotStep = $activityChart->count() > 1
        ? ($plotRight - $plotLeft) / ($activityChart->count() - 1)
        : 0;
    $activityPoints = $activityChart->values()->map(function ($day, $index) use ($activityMax, $plotLeft, $plotStep, $plotTop, $plotBaseline) {
        $ratio = $activityMax > 0 ? $day['total'] / $activityMax : 0;

        return [
            'x' => round($plotLeft + ($plotStep * $index), 2),
            'y' => round($plotBaseline - (($plotBaseline - $plotTop) * $ratio), 2),
        ];
    });
    $activityFirstPoint = $activityPoints->first() ?: ['x' => $plotLeft, 'y' => $plotBaseline];
    $activityLastPoint = $activityPoints->last() ?: ['x' => $plotRight, 'y' => $plotBaseline];
    $activityLinePath = 'M '.$activityFirstPoint['x'].' '.$activityFirstPoint['y'];
    foreach ($activityPoints->skip(1) as $index => $point) {
        $previous = $activityPoints[$index - 1];
        $middle = round(($previous['x'] + $point['x']) / 2, 2);
        $activityLinePath .= ' C '.$middle.' '.$previous['y']
            .' '.$middle.' '.$point['y']
            .' '.$point['x'].' '.$point['y'];
    }
    $activityAreaPath = $activityLinePath
        .' L '.$activityLastPoint['x'].' '.$plotBaseline
        .' L '.$activityFirstPoint['x'].' '.$plotBaseline.' Z';
@endphp
<div class="dashboard-charts">
<article class="chart-card activity-card">
    <div class="chart-heading"><div><h3>Alerts in the last 7 days</h3><p>Physical-device events by day</p></div><div class="chart-total-wrap"><span class="chart-total">{{ $statusTotal }} total</span><small>7-day window</small></div></div>
    <div class="activity-detail-strip" aria-label="Activity summary">
        <span><i class="legend-swatch fall"></i>{{ $possibleTotal }} possible falls</span>
        <span><i class="legend-swatch emergency"></i>{{ $manualTotal }} manual emergencies</span>
        @if($peakDay && $peakDay['total'] > 0)<span class="activity-peak">Peak: {{ $peakDay['label'] }} {{ $peakDay['total'] }}</span>@endif
    </div>
    @if($statusTotal > 0)
    <div class="activity-chart" role="img" aria-label="Seven-day alert activity. {{ $statusTotal }} total physical-device events.">
        <div class="activity-scale" aria-hidden="true"><span>{{ $activityMax }}</span><span>{{ $activityMidScale }}</span><span>0</span></div>
        <div class="activity-line-plot" aria-hidden="true">
            <svg viewBox="0 0 700 160" preserveAspectRatio="none" focusable="false">
                <defs>
                    <linearGradient id="activity-area-fill" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#79b9f3" stop-opacity=".28"></stop>
                        <stop offset="100%" stop-color="#79b9f3" stop-opacity=".02"></stop>
                    </linearGradient>
                </defs>
                <path class="activity-area" d="{{ $activityAreaPath }}"></path>
                <path class="activity-line" d="{{ $activityLinePath }}"></path>
                @foreach($activityPoints as $index => $point)
                    <circle class="activity-point" data-day-index="{{ $index }}" cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="4"></circle>
                @endforeach
            </svg>
        </div>
        <div class="activity-bars">
            @foreach($activityChart as $day)
            <div class="activity-day" data-day-index="{{ $loop->index }}" aria-describedby="activity-tooltip-{{ $loop->index }}" aria-label="{{ $day['date'] }}: {{ $day['possible_fall'] }} possible falls and {{ $day['manual_emergency'] }} manual emergencies">
                <div class="activity-track">
                    @if($day['total'] > 0)
                    <div class="activity-stack" style="--bar-height: {{ max(8, (int) round(($day['total'] / $activityMax) * 100)) }}%;">
                        @if($day['possible_fall'] > 0)<span class="activity-segment fall" style="flex-grow: {{ $day['possible_fall'] }};"></span>@endif
                        @if($day['manual_emergency'] > 0)<span class="activity-segment emergency" style="flex-grow: {{ $day['manual_emergency'] }};"></span>@endif
                    </div>
                    @endif
                </div>
                <div class="activity-day-label"><strong>{{ $day['label'] }}</strong><small>{{ $day['total'] }}</small></div>
                <div class="activity-tooltip" id="activity-tooltip-{{ $loop->index }}" role="tooltip">
                    <strong>{{ $day['date'] }}</strong>
                    <span>{{ $day['total'] }} {{ Str::plural('event', $day['total']) }}</span>
                    <small><i class="tooltip-swatch fall"></i>{{ $day['possible_fall'] }} possible fall{{ $day['possible_fall'] === 1 ? '' : 's' }}</small>
                    <small><i class="tooltip-swatch emergency"></i>{{ $day['manual_emergency'] }} manual emergenc{{ $day['manual_emergency'] === 1 ? 'y' : 'ies' }}</small>
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @else
    <div class="chart-empty"><span class="chart-empty-mark">&mdash;</span><strong>No alerts recorded</strong><p>Physical-device events will appear here when received.</p></div>
    @endif
    <div class="chart-legend" aria-label="Alert type legend"><span><i class="legend-swatch fall"></i>Possible fall</span><span><i class="legend-swatch emergency"></i>Manual emergency</span></div>
</article>
<article class="chart-card status-card">
    <div class="chart-heading"><div><h3>Incident status</h3><p>Same 7-day event population</p></div></div>
    <div class="status-chart">
        <div class="status-donut" style="background: conic-gradient({{ $statusGradient }});" role="img" aria-label="Incident status distribution for {{ $statusTotal }} events">
            <div class="status-donut-center"><strong>{{ $statusTotal }}</strong><span>{{ Str::plural('event', $statusTotal) }}</span></div>
        </div>
        <div class="status-legend">
            @foreach($statusChart as $status)
            <div class="status-row"><span><i class="status-swatch" style="--status-color: {{ $status['color'] }};"></i>{{ $status['label'] }}</span><strong>{{ $status['count'] }}</strong></div>
            @endforeach
        </div>
    </div>
    @if($statusTotal === 0)<p class="status-empty">No incident status to review for this period.</p>@endif
</article>
</div>
