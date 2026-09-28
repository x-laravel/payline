<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $approved ? 'Payment received' : 'Payment failed' }}</title>
</head>
<body>
<p>{{ $approved ? 'Payment received.' : ($message ?: 'Payment failed.') }}</p>
@php($payload = ['type' => 'payline', 'status' => $status, 'approved' => $approved, 'message' => $message])
<script>
if (window.parent !== window) {
    window.parent.postMessage(@json($payload), '*');
}
</script>
</body>
</html>
