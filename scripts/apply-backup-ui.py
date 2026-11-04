#!/usr/bin/env python3
"""Apply narrow Laravel integration to a reviewed source/candidate, preserving phase code."""
import sys
from pathlib import Path

root = Path(sys.argv[1])
p = root / 'app/Providers/AppServiceProvider.php'
s = p.read_text()
if "Gate::define('manage-platform-backups'" not in s:
    s = s.replace('        Gate::policy(Conversation::class, ConversationPolicy::class);', "        Gate::define('manage-platform-backups', fn (User $user) => $user->role === Role::PlatformAdmin && $user->status === 'active');\n        RateLimiter::for('platform-backups', fn ($request) => Limit::perMinute(6)->by('platform-backups:'.$request->user()->id));\n        Gate::policy(Conversation::class, ConversationPolicy::class);")
    assert "Gate::define('manage-platform-backups'" in s
p.write_text(s)
p = root / 'routes/web.php'
s = p.read_text()
if "require __DIR__.'/backups.php';" not in s:
    s += "\nrequire __DIR__.'/backups.php';\n"
p.write_text(s)
p = root / 'resources/views/layout.blade.php'
s = p.read_text()
if "@include('backups.navigation')" not in s:
    s = s.replace('</nav>', "\n@include('backups.navigation')\n</nav>")
s = s.replace("@include('backups.navigation')", "\n@include('backups.navigation')\n")
p.write_text(s)
p = root / 'bootstrap/app.php'
s = p.read_text()
if "'r2_secret_access_key'" not in s:
    s = s.replace("$exceptions->dontFlash([", "$exceptions->dontFlash(['private_key', 'r2_private_key', 'r2_account_id', 'r2_access_key_id', 'r2_secret_access_key', 'r2_recipient', 'r2_offsite_ack', ")
p.write_text(s)
p = root / 'app/Services/Audit.php'
s = p.read_text()
if "'backup.settings_updated'" not in s:
    s = s.replace('if (! in_array($event, [', "if (! in_array($event, ['backup.settings_updated', 'backup.requested', ")
p.write_text(s)
