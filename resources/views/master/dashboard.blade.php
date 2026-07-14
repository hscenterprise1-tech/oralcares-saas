<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>OralCares Master Portal</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-gray-100 dark:bg-gray-900 text-gray-900 dark:text-gray-100 min-h-screen flex items-center justify-center">
        <div class="bg-white dark:bg-gray-800 p-8 rounded-lg shadow-md w-full max-w-md">
            <h1 class="text-2xl font-bold mb-6 text-center text-indigo-600 dark:text-indigo-400">OralCares Master Portal</h1>
            
            <div class="mb-6 space-y-2">
                <p><span class="font-semibold">Name:</span> {{ Auth::user()->name }}</p>
                <p><span class="font-semibold">Email:</span> {{ Auth::user()->email }}</p>
            </div>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="w-full bg-red-600 hover:bg-red-700 text-white font-semibold py-2 px-4 rounded transition duration-150 ease-in-out">
                    Log Out
                </button>
            </form>
        </div>
    </body>
</html>
