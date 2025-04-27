<?php

namespace App\Http\Controllers;

use App\Http\Requests\SetupRequest;
use App\Models\Team;
use App\Models\User;
use App\Settings\InstanceSettings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class SetupController extends Controller
{
    public function index(InstanceSettings $settings)
    {
        if ($settings->setup_done) {
            return to_route('home');
        }

        $serverIp = request()->server('SERVER_ADDR');
        $ipVersion = filter_var($serverIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 'IPv4' : 'IPv6';

        $serverDomain = request()->server('SERVER_NAME');
        $ipv4 = $ipVersion === 'IPv4' ? $serverIp : null;
        $ipv6 = $ipVersion === 'IPv6' ? $serverIp : null;

        return inertia('setup/index', compact('serverDomain', 'ipv4', 'ipv6'));
    }

    public function store(SetupRequest $request, InstanceSettings $settings)
    {
        $settings->name = $request->name;
        $settings->domain = $request->domain;
        $settings->publicly_accessible = $request->publicly_accessible;
        $settings->ipv4 = $request->ipv4;
        $settings->ipv6 = $request->ipv6;
        $settings->admin_email = $request->admin_email ?? $request->email;
        $settings->setup_done = true;
        $settings->save();

        $user = User::create([
            'name' => $request->username,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);
        Auth::login($user, true);
        Team::newForUser($user);

        return to_route('home');
    }
}
