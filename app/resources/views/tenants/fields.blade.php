<label>Nome<input name="name" value="{{ is_string(old('name', $tenant->name ?? '')) ? old('name', $tenant->name ?? '') : '' }}" maxlength="120" required></label>
<label>Slug<input name="slug" value="{{ is_string(old('slug', $tenant->slug ?? '')) ? old('slug', $tenant->slug ?? '') : '' }}" maxlength="80" pattern="[a-z0-9]+(-[a-z0-9]+)*" required></label>
<label>Status<select name="status">@foreach(['active','disabled'] as $status)<option value="{{ $status }}" @selected(old('status', $tenant->status ?? 'active') === $status)>{{ $status }}</option>@endforeach</select></label>
