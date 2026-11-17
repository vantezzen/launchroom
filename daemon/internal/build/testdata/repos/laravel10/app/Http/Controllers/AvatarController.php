<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class AvatarController extends Controller
{
    public function update(Request $request)
    {
        $path = $request->file('avatar')->store('avatars', 'public');

        return back()->with('path', $path);
    }
}
