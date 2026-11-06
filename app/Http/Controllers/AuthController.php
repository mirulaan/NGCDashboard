<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth; // 1. Added missing Auth import
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;

class AuthController extends Controller
{
    // Displays the login.blade.php HTML page
    public function showLogin()
    {
        return view('login');
    }

    // Handles the actual login form submission
    public function authenticate(Request $request)
    {
        // 2 & 3. Fixed validation syntax and assigned it to the $credentials variable
        $credentials = $request->validate([
            'name' => ['required'], // We use 'name' as the Login ID
            'password' => ['required'],
        ]);

        // Attempt to log in using those credentials
        if (Auth::attempt($credentials)) {
            $request->session()->regenerate();

            // Redirect to the secure test page
            return redirect()->intended('fleet');
        }

        return back()->withErrors([
            'name' => 'The provided Login ID or password do not match our records.',
        ]);
    }

    // Handle logout
    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        
        return redirect('fleet');
    }

    // Show the Settings page
    public function showSettings()
    {
        return view('settings');
    }

    // Handle the password change request
    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => ['required', 'current_password'], // Validates against the user's actual password
            'new_password' => ['required', 'min:8', 'confirmed'],   // Ensures new_password and new_password_confirmation match
        ]);

        $user = $request->user();
        $user->password = Hash::make($request->new_password);
        $user->save();

        return back()->with('success', 'Password updated successfully!');
    }

    // Handle SFTP Secure Test Page
    public function sftp(Request $request)
    {
        // Default to root '/' if no path is provided
        $path = $request->input('path', '/');
        $contents = [];
        $error = null;

        try {
            $disk = Storage::disk('sftp');
            
            // Fetch directories and files from SFTP
            $directories = $disk->directories($path);
            $files = $disk->files($path);
            
            // Format directories for the UI
            foreach ($directories as $dir) {
                try {
                    $timestamp = $disk->lastModified($dir);
                } catch (\Exception $e) {
                    $timestamp = 0; // Fallback if SFTP server restricts directory timestamps
                }
                $contents[] = [
                    'type' => 'folder', 
                    'name' => basename($dir),
                    'timestamp' => $timestamp,
                    'date' => $timestamp ? date('Y-m-d H:i', $timestamp) : '-'
                ];
            }
            
            // Format files for the UI
            foreach ($files as $file) {
                try {
                    $timestamp = $disk->lastModified($file);
                } catch (\Exception $e) {
                    $timestamp = 0;
                }
                $contents[] = [
                    'type' => 'file', 
                    'name' => basename($file),
                    'timestamp' => $timestamp,
                    'date' => $timestamp ? date('Y-m-d H:i', $timestamp) : '-'
                ];
            }

            // Sort contents by timestamp (Newest files/folders at the top)
            usort($contents, function ($a, $b) {
                return $b['timestamp'] <=> $a['timestamp'];
            });

        } catch (\Exception $e) {
            // Catch any connection or permission errors
            $error = 'Could not connect to SFTP or read path: ' . $e->getMessage();
        }

        return view('sftp', compact('path', 'contents', 'error'));
    }

    // Fetch SFTP options for Node Edit Mode
    public function getSftpOptions($plant)
    {
        $baseDir = env('SFTP_MAIN_DIR', '/Software/Model');
        $disk = Storage::disk('sftp');
        
        // Map the frontend keys to their specific SFTP folder paths
        $mappings = [
            'Post'          => "/3-NewPost P{$plant}",
            'NoGlove'       => '/7-NoGlove',
            'Pre'           => '/1-Pre',
            'GSS Defect'    => '/2-GSS_Defect',
            'Post Black'    => '/4-NewBlack',
            'NoGlove Black' => '/7-NoGlove',
            'Pre Black'     => '/5-PreBlack',
            'GSS Black'     => '/2-GSS_Defect',
            'GSS Machine'   => '/2-GSS_Machine'
        ];
        
        $options = [];
        
        foreach($mappings as $key => $subDir) {
            try {
                $files = $disk->files($baseDir . $subDir);
                $fileData = [];
                
                foreach($files as $file) {
                    try {
                        $fileData[] = [
                            'name' => basename($file),
                            'time' => $disk->lastModified($file)
                        ];
                    } catch (\Exception $e) {
                        // Fallback if timestamp can't be read
                        $fileData[] = ['name' => basename($file), 'time' => 0];
                    }
                }
                
                // Sort descending (newest first)
                usort($fileData, fn($a, $b) => $b['time'] <=> $a['time']);
                
                // Keep only top 10 and extract just the filenames
                $options[$key] = array_column(array_slice($fileData, 0, 10), 'name');
            } catch (\Exception $e) {
                $options[$key] = []; // Return empty array if folder fails
            }
        }
        
        return response()->json($options);
    }

    // Receive the final JSON wrapper and forward it to the external API
    public function updateNodeConfig(Request $request)
    {
        // The JSON is sent as a stringified payload inside a hidden input
        $payload = json_decode($request->input('config_payload'), true);
        
        // Read the API URL from the .env file
        $apiUrl = env('CONFIG_API_URL');

        try {
            // Forward the JSON payload to the external API
            $response = Http::post($apiUrl, $payload);
            
            if ($response->successful()) {
                return back()->with('success', 'Configuration successfully updated and sent to CONFIG API!');
            } else {
                return back()->with('error', 'Configuration saved, but the CONFIG API returned an error: ' . $response->status());
            }
        } catch (\Exception $e) {
            // Handle timeout or connection issues to the external API
            return back()->with('error', 'Failed to connect to the CONFIG API: ' . $e->getMessage());
        }
    }

}