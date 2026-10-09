<?php
namespace App\Http\Middleware;
use App\Models\Device;
use Closure;
use Illuminate\Http\Request;

class DeviceAuthentication
{
    public function handle(Request $request, Closure $next)
    {
        $key = $request->header('X-Device-Key', '');
        abort_unless(strlen($key) >= 32 && strlen($key) <= 256, 401, 'Invalid device key.');
        $device = Device::where('key_hash', hash('sha256', $key))->where('is_active', true)
            ->where('is_simulated', false)->first();
        abort_unless($device, 401, 'Invalid or disabled device.');
        $request->attributes->set('device', $device);
        return $next($request);
    }
}
