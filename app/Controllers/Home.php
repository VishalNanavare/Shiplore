<?php

namespace App\Controllers;

class Home extends BaseController
{
    public function index(): string
    {
        // Themed login screen (stub form; wired to real session auth in Phase 5).
        return view('auth/login', ['title' => 'Sign In · Omnichannel Commerce']);
    }
}
