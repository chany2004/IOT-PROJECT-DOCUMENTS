<?php
namespace App\Http\Controllers;
use App\Services\EventIngestion;
use Illuminate\Http\Request;

class DeviceApiController extends Controller
{
    public function store(Request $request, EventIngestion $service)
    {
        abort_if(strlen($request->getContent()) > 16384, 413);
        [$event, $created] = $service->accept($request->attributes->get('device'), $request->all());
        return response()->json(['success' => true, 'event_id' => $event->public_id,
            'status' => $event->status, 'verification_status' => $event->verification_status,
            'duplicate' => !$created], $created ? 201 : 200);
    }
    public function heartbeat(Request $request)
    {
        $data = $request->validate(['sensor_ok' => ['sometimes', 'boolean']]);
        $device = $request->attributes->get('device');
        $updates = ['last_seen_at' => now()] + $data;

        // The Windows hotspot may lease a different address after a camera
        // reboot. An authenticated camera heartbeat is the safest place to
        // keep its private capture address current.
        if ($device->type === 'esp32_cam') {
            $ip = $request->ip();
            $privateIpv4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                && (str_starts_with($ip, '10.')
                    || str_starts_with($ip, '192.168.')
                    || preg_match('/^172\.(1[6-9]|2[0-9]|3[01])\./', $ip));
            if ($privateIpv4) {
                $updates['camera_ip'] = $ip;
            }
        }

        $device->update($updates);
        return response()->json(['success' => true, 'server_time' => now()->toIso8601String()]);
    }
}
