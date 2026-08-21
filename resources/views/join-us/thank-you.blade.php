<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Application received</title>
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-screen items-center justify-center bg-gray-50 text-gray-900 antialiased">
    <div class="mx-auto max-w-md px-4 py-10 text-center">
        <h1 class="text-2xl font-semibold">Thanks for applying!</h1>
        <p class="mt-3 text-sm text-gray-600">
            Your application has been received and is pending review. We'll be in touch once your account has been
            activated.
        </p>
        <a href="{{ route('join-us.create') }}" class="mt-6 inline-block text-sm font-medium text-indigo-600">
            Back to the form
        </a>
    </div>
</body>
</html>
