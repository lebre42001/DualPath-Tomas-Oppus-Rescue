<fieldset class="legal-acknowledgment">
    <legend>Before you continue</legend>
    <input type="hidden" name="legal_version" value="{{ config('legal.version') }}">
    <label class="legal-check" for="terms_accepted">
        <input id="terms_accepted" name="terms_accepted" type="checkbox" value="1" required @checked(old('terms_accepted') && old('legal_version') === config('legal.version')) @error('terms_accepted') aria-invalid="true" aria-describedby="terms-error" @enderror>
        <span>I agree to the <a href="{{ route('legal.terms') }}" target="_blank" rel="noopener">Terms &amp; Conditions</a> and <a href="{{ route('legal.license') }}" target="_blank" rel="noopener">App License</a> (open in a new tab).</span>
    </label>
    @error('terms_accepted') <p class="legal-error" id="terms-error">{{ $message }}</p> @enderror
    <label class="legal-check" for="privacy_acknowledged">
        <input id="privacy_acknowledged" name="privacy_acknowledged" type="checkbox" value="1" required @checked(old('privacy_acknowledged') && old('legal_version') === config('legal.version')) @error('privacy_acknowledged') aria-invalid="true" aria-describedby="privacy-error" @enderror>
        <span>I have read the <a href="{{ route('legal.privacy') }}" target="_blank" rel="noopener">Privacy Policy</a> about account details, location, photos, and face/blink verification (opens in a new tab).</span>
    </label>
    @error('privacy_acknowledged') <p class="legal-error" id="privacy-error">{{ $message }}</p> @enderror
    @error('legal_version') <p class="legal-error" role="alert">{{ $message }}</p> @enderror
    <p class="legal-small">Acknowledging this notice is not permission for advertising, publication, or model training with your reports.</p>
</fieldset>
