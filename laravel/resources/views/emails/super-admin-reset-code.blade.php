<p>Hello {{ $username }},</p>

<p>Someone requested a password change for your Super Admin account.
Enter the code below to confirm it's really you. If you didn't request
this, you can ignore this email — the code expires on its own.</p>

<p style="font-size: 28px; font-weight: 700; letter-spacing: 4px;">{{ $code }}</p>

<p>This code expires in {{ $expiresInMinutes }} minutes and can only be used once.</p>
