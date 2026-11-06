<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class testController extends Controller
{
    // Updated webhook to sort into the correct line/side bucket
    public function test(Request $request)
    {
        return response()->json(['message' => "Test endpoint called successfully"], 200);
    }
}