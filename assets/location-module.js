/**
 * TPB Location Module
 * 
 * Handles all location lookup and selection functionality.
 * Uses local database via api/zip-lookup.php - NO external APIs.
 * 
 * Flow:
 * 1. User enters zip code OR searches by town name
 * 2. Confirm location
 * 3. Save to profile (with district lookup from coordinates)
 * 
 * Dependencies:
 * - STATES array (from PHP)
 * - API_BASE constant
 * - api/zip-lookup.php
 * - api/lookup-districts.php
 * 
 * @package TPB
 * @since 2025-12-22
 */

const TPBLocation = (function() {
    'use strict';
    
    // API base path - use global if defined, otherwise default to 'api'
    const apiBase = (typeof API_BASE !== 'undefined') ? API_BASE : 'api';
    
    // Private state
    let pendingLocationData = null;
    let onLocationSaved = null;
    
    // Store current location for replacement warning
    let currentUserLocation = null;
    
    /**
     * Look up location by zip code
     */
    async function lookupZip(zipCode) {
        try {
            const response = await fetch(`${apiBase}/zip-lookup.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    action: 'lookup_zip',
                    zip_code: zipCode 
                })
            });
            
            const result = await response.json();
            return result.status === 'success' ? result.data : null;
        } catch (err) {
            console.error('Zip lookup error:', err);
            return null;
        }
    }
    
    /**
     * Search towns by name (for autocomplete)
     */
    async function searchTowns(query, stateCode = null) {
        if (query.length < 2) return [];
        
        try {
            const body = {
                action: 'search_towns',
                query: query,
                limit: 10
            };
            if (stateCode) body.state_code = stateCode;
            
            const response = await fetch(`${apiBase}/zip-lookup.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            });
            
            const result = await response.json();
            return result.status === 'success' ? result.data : [];
        } catch (err) {
            console.error('Town search error:', err);
            return [];
        }
    }
    
    /**
     * Get coordinates for a town
     */
    async function getTownCoords(townName, stateCode) {
        try {
            const response = await fetch(`${apiBase}/zip-lookup.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'get_coords',
                    town_name: townName,
                    state_code: stateCode
                })
            });
            
            const result = await response.json();
            return result.status === 'success' ? result.data : null;
        } catch (err) {
            console.error('Get coords error:', err);
            return null;
        }
    }
    
    /**
     * Look up districts from coordinates
     */
    async function lookupDistricts(lat, lon) {
        const defaultDistricts = {
            us_congress_district: null,
            state_senate_district: null,
            state_house_district: null
        };
        
        if (!lat || !lon) return defaultDistricts;
        
        try {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 5000);
            
            const response = await fetch(`${apiBase}/lookup-districts.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ latitude: lat, longitude: lon }),
                signal: controller.signal
            });
            clearTimeout(timeoutId);
            
            const result = await response.json();
            if (result.status === 'success' && result.districts) {
                return result.districts;
            }
        } catch (err) {
            console.log('District lookup failed or timed out:', err);
        }
        
        return defaultDistricts;
    }
    
    /**
     * Create and show the location entry modal
     * Zip code first, with town search as fallback
     */
    function showZipEntryModal(options = {}) {
        // Remove any existing modal
        const existing = document.getElementById('locationModal');
        if (existing) existing.remove();
        
        const prefillZip = options.prefillZip || '';
        
        // Store current location if provided (for replacement warning)
        currentUserLocation = options.currentLocation || null;
        
        const modal = document.createElement('div');
        modal.className = 'location-confirm-overlay';
        modal.id = 'locationModal';
        modal.innerHTML = `
            <div class="location-confirm-modal">
                <h3 style="margin-bottom: 1rem;">📍 Set Your Location</h3>
                
                <div class="zip-entry-section" style="margin-bottom: 1.5rem;">
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Enter your zip code:</label>
                    <div style="display: flex; gap: 0.5rem;">
                        <input type="text" id="zipInput" 
                               inputmode="numeric"
                               pattern="[0-9]{5}" 
                               maxlength="5" 
                               placeholder="e.g., 06260"
                               value="${prefillZip}"
                               style="flex: 1; padding: 0.75rem; font-size: 1.1rem; border: 1px solid #ccc; border-radius: 8px; text-align: center; letter-spacing: 2px;">
                        <button id="zipLookupBtn" class="btn btn-primary" style="white-space: nowrap;">
                            Look Up
                        </button>
                    </div>
                    <div id="zipStatus" style="margin-top: 0.5rem; min-height: 1.5rem;"></div>
                </div>
                
                <div class="divider" style="text-align: center; margin: 1rem 0; color: #888;">
                    ─── or search by town name ───
                </div>
                
                <div class="town-search-section" style="margin-bottom: 1.5rem;">
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Search for your town:</label>
                    <div style="position: relative;">
                        <input type="text" id="townSearchInput" 
                               placeholder="Start typing town name..."
                               style="width: 100%; padding: 0.75rem; font-size: 1rem; border: 1px solid #ccc; border-radius: 8px;">
                        <div id="townSearchResults" class="autocomplete-results"></div>
                    </div>
                </div>
                
                <div class="modal-actions" style="display: flex; gap: 0.5rem; margin-top: 1.5rem;">
                    <button id="skipLocationBtn" class="btn btn-text" style="flex: 1;">
                        Skip for now
                    </button>
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);
        addAutocompleteStyles();
        
        // Wire up zip lookup
        const zipInput = document.getElementById('zipInput');
        const zipLookupBtn = document.getElementById('zipLookupBtn');
        const zipStatus = document.getElementById('zipStatus');
        
        zipInput.addEventListener('input', () => {
            zipInput.value = zipInput.value.replace(/\D/g, '').slice(0, 5);
            zipStatus.innerHTML = '';
        });
        
        zipInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter' && zipInput.value.length === 5) {
                handleZipLookup();
            }
        });
        
        zipLookupBtn.addEventListener('click', handleZipLookup);
        
        // Auto-lookup if prefilled
        if (prefillZip && prefillZip.length === 5) {
            setTimeout(handleZipLookup, 300);
        }
        
        async function handleZipLookup() {
            const zip = zipInput.value.trim();
            if (zip.length !== 5) {
                zipStatus.innerHTML = '<span style="color: #e63946;">Please enter a 5-digit zip code</span>';
                return;
            }
            
            zipLookupBtn.disabled = true;
            zipLookupBtn.textContent = 'Looking up...';
            zipStatus.innerHTML = '<span style="color: #666;">Searching...</span>';
            
            const location = await lookupZip(zip);
            
            zipLookupBtn.disabled = false;
            zipLookupBtn.textContent = 'Look Up';
            
            if (location) {
                showLocationConfirmation(location);
            } else {
                zipStatus.innerHTML = '<span style="color: #e63946;">Zip code not found. Try searching by town name.</span>';
            }
        }
        
        // Wire up town search
        const townInput = document.getElementById('townSearchInput');
        const townResults = document.getElementById('townSearchResults');
        let debounceTimer;
        
        townInput.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            const query = this.value.trim();
            
            if (query.length < 2) {
                townResults.classList.remove('show');
                return;
            }
            
            debounceTimer = setTimeout(async () => {
                const towns = await searchTowns(query);
                
                if (towns.length > 0) {
                    townResults.innerHTML = towns.map(t => `
                        <div onclick="TPBLocation.selectSearchResult(${t.town_id}, '${escapeHtml(t.town_name)}', '${t.state_code}', ${t.latitude || 'null'}, ${t.longitude || 'null'})">
                            <span class="town-name">${escapeHtml(t.town_name)}</span>, 
                            <span class="state-abbrev">${t.state_code}</span>
                        </div>
                    `).join('');
                    townResults.classList.add('show');
                } else {
                    townResults.innerHTML = '<div style="color: #888; padding: 0.5rem;">No towns found</div>';
                    townResults.classList.add('show');
                }
            }, 300);
        });
        
        // Wire up skip button
        document.getElementById('skipLocationBtn').addEventListener('click', () => {
            modal.remove();
            if (options.onSkip) options.onSkip();
        });
        
        // Close on overlay click
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                modal.remove();
                if (options.onSkip) options.onSkip();
            }
        });
        
        // Store callback
        onLocationSaved = options.onSaved;
        
        // Focus zip input
        zipInput.focus();
    }
    
    /**
     * Show location confirmation
     */
    function showLocationConfirmation(location) {
        const modal = document.getElementById('locationModal');
        if (!modal) return;
        
        const town = location.place || location.town;
        const state = location.state_code || location.state;
        
        // Check if this would replace an existing location
        const isReplacing = currentUserLocation && currentUserLocation.town && currentUserLocation.state;
        const replacementWarning = isReplacing ? `
            <div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; padding: 1rem; margin-bottom: 1rem;">
                <div style="font-weight: 600; color: #856404; margin-bottom: 0.5rem;">⚠️ Replace saved location?</div>
                <div style="color: #856404; font-size: 0.9rem;">
                    Your current location is <strong>${escapeHtml(currentUserLocation.town)}, ${escapeHtml(currentUserLocation.state)}</strong>
                </div>
            </div>
        ` : '';
        
        const modalContent = modal.querySelector('.location-confirm-modal');
        modalContent.innerHTML = `
            <h3 style="margin-bottom: 1rem;">📍 Confirm Your Location</h3>
            
            ${replacementWarning}
            
            <div style="text-align: center; padding: 1.5rem; background: #f8f9fa; border-radius: 12px; margin-bottom: 1.5rem;">
                <div style="font-size: 1.5rem; font-weight: 600; color: #1a1a2e;">${escapeHtml(town)}, ${state}</div>
                ${location.county ? `<div style="font-size: 0.9rem; color: #666; margin-top: 0.5rem;">${escapeHtml(location.county)} County</div>` : ''}
            </div>
            
            <p style="text-align: center; margin-bottom: 1.5rem; color: #555;">
                ${isReplacing ? 'Save this as your new location?' : 'Is this your location?'}
            </p>
            
            <div class="modal-actions" style="display: flex; gap: 0.5rem;">
                <button id="confirmNoBtn" class="btn btn-secondary" style="flex: 1;">
                    ${isReplacing ? 'Cancel' : 'No, try again'}
                </button>
                <button id="confirmYesBtn" class="btn btn-primary" style="flex: 1;">
                    ${isReplacing ? 'Yes, replace it' : 'Yes, that\'s right'}
                </button>
            </div>
        `;
        
        // Store location for confirmation
        pendingLocationData = {
            town: town,
            state: state,
            state_id: location.state_id,
            state_name: location.state_name,
            town_id: location.town_id,
            latitude: location.latitude,
            longitude: location.longitude,
            zip_code: location.zip_code || null,
            street_address: location.street_address || null,
            districts: location.districts || null
        };
        
        // Wire up buttons
        document.getElementById('confirmNoBtn').addEventListener('click', () => {
            pendingLocationData = null;
            showZipEntryModal({ onSaved: onLocationSaved });
        });
        
        document.getElementById('confirmYesBtn').addEventListener('click', async () => {
            const btn = document.getElementById('confirmYesBtn');
            btn.disabled = true;
            btn.textContent = 'Saving...';
            
            // Get districts from coordinates
            if (pendingLocationData.latitude && pendingLocationData.longitude) {
                pendingLocationData.districts = await lookupDistricts(
                    pendingLocationData.latitude, 
                    pendingLocationData.longitude
                );
            }
            
            // Save and check result
            const saveResult = await saveLocationToProfile(pendingLocationData);
            
            if (saveResult.success) {
                modal.remove();
                if (onLocationSaved) {
                    onLocationSaved(pendingLocationData);
                }
            } else {
                // Show error, let user try again
                btn.disabled = false;
                btn.textContent = 'Yes, that\'s right';
                alert('Failed to save location. Please try again.');
            }
        });
    }
    
    /**
     * Handle selection from town search results
     */
    async function selectSearchResult(townId, townName, stateCode, lat, lon) {
        const results = document.getElementById('townSearchResults');
        if (results) results.classList.remove('show');
        
        // Get coordinates if not provided
        if (!lat || !lon) {
            const coords = await getTownCoords(townName, stateCode);
            if (coords) {
                lat = coords.latitude;
                lon = coords.longitude;
            }
        }
        
        // Get state info
        const stateObj = typeof STATES !== 'undefined' ? 
            STATES.find(s => s.abbreviation === stateCode) : null;
        
        pendingLocationData = {
            town: townName,
            state: stateCode,
            state_id: stateObj ? stateObj.state_id : null,
            state_name: stateObj ? stateObj.state_name : null,
            town_id: townId,
            latitude: lat,
            longitude: lon,
            zip_code: null,
            street_address: null,
            districts: null
        };
        
        showLocationConfirmation(pendingLocationData);
    }
    
    /**
     * Save location to user profile via API
     */
    async function saveLocationToProfile(locationData) {
        try {
            // Get session_id from cookie
            const sessionMatch = document.cookie.match(/tpb_civic_session=([^;]+)/);
            let sessionId = sessionMatch ? sessionMatch[1] : null;
            
            // If no session cookie, create one
            if (!sessionId) {
                sessionId = 'civic_' + Math.random().toString(36).substr(2, 9) + '_' + Date.now();
                document.cookie = 'tpb_civic_session=' + sessionId + '; path=/; max-age=31536000';
            }
            
            const payload = {
                session_id: sessionId,
                town: locationData.town,
                state: locationData.state,
                latitude: locationData.latitude,
                longitude: locationData.longitude
            };
            
            // Include zip and street address if available
            if (locationData.zip_code) payload.zip_code = locationData.zip_code;
            if (locationData.street_address) payload.street_address = locationData.street_address;
            
            if (locationData.districts) {
                payload.us_congress_district = locationData.districts.us_congress_district;
                payload.state_senate_district = locationData.districts.state_senate_district;
                payload.state_house_district = locationData.districts.state_house_district;
            }
            
            console.log('[TPB] Saving location:', payload);
            
            const response = await fetch(`${apiBase}/save-profile.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify(payload)
            });
            
            const result = await response.json();
            console.log('[TPB] Save result:', result);
            
            if (result.status === 'success') {
                // Log civic points for setting location
                try {
                    await fetch(`${apiBase}/log-civic-click.php`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action_type: 'location_saved',
                            page_name: 'profile',
                            element_id: 'location_modal',
                            session_id: sessionId,
                            extra_data: {
                                town: locationData.town,
                                state: locationData.state
                            }
                        })
                    });
                } catch (e) {
                    console.warn('Civic logging failed:', e);
                }
                return { success: true, result: result };
            } else {
                console.error('Failed to save location:', result.message);
                return { success: false, result: result };
            }
        } catch (err) {
            console.error('Error saving location:', err);
            return { success: false, error: err.message };
        }
    }
    
    /**
     * Add CSS styles for autocomplete and modal
     */
    function addAutocompleteStyles() {
        if (document.getElementById('tpb-location-styles')) return;
        
        const styles = document.createElement('style');
        styles.id = 'tpb-location-styles';
        styles.textContent = `
            /* Modal overlay */
            .location-confirm-overlay {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0,0,0,0.85);
                z-index: 2000;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            
            .location-confirm-modal {
                background: #1a1a2a;
                border: 2px solid #d4af37;
                border-radius: 12px;
                padding: 30px;
                max-width: 450px;
                width: 100%;
                color: #e0e0e0;
            }
            
            .location-confirm-modal h3 {
                color: #d4af37;
                margin: 0 0 1rem 0;
                text-align: center;
            }
            
            .location-confirm-modal .btn {
                padding: 0.75rem 1.5rem;
                border-radius: 8px;
                border: none;
                cursor: pointer;
                font-size: 1rem;
                transition: all 0.2s;
            }
            
            .location-confirm-modal .btn-primary {
                background: #d4af37;
                color: #1a1a2a;
                font-weight: 600;
            }
            
            .location-confirm-modal .btn-primary:hover {
                background: #e5c04b;
            }
            
            .location-confirm-modal .btn-secondary {
                background: #333;
                color: #e0e0e0;
            }
            
            .location-confirm-modal .btn-secondary:hover {
                background: #444;
            }
            
            .location-confirm-modal .btn-text {
                background: transparent;
                color: #888;
            }
            
            .location-confirm-modal .btn-text:hover {
                color: #aaa;
            }
            
            .location-confirm-modal input[type="text"] {
                background: #0a0a1a;
                border: 1px solid #333;
                color: #e0e0e0;
            }
            
            .location-confirm-modal input[type="text"]:focus {
                outline: none;
                border-color: #d4af37;
            }
            
            /* Autocomplete dropdown */
            .autocomplete-results {
                position: absolute;
                top: 100%;
                left: 0;
                right: 0;
                background: #1a1a2a;
                border: 1px solid #444;
                border-radius: 8px;
                max-height: 250px;
                overflow-y: auto;
                display: none;
                z-index: 2001;
                box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            }
            .autocomplete-results.show {
                display: block;
            }
            .autocomplete-results > div {
                padding: 0.75rem 1rem;
                cursor: pointer;
                border-bottom: 1px solid #333;
                color: #e0e0e0;
            }
            .autocomplete-results > div:last-child {
                border-bottom: none;
            }
            .autocomplete-results > div:hover {
                background: #2a2a3a;
            }
            .autocomplete-results .town-name {
                font-weight: 500;
                color: #fff;
            }
            .autocomplete-results .state-abbrev {
                color: #888;
            }
        `;
        document.head.appendChild(styles);
    }
    
    /**
     * Escape HTML to prevent XSS
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Public API
    return {
        lookupZip,
        searchTowns,
        getTownCoords,
        lookupDistricts,
        showZipEntryModal,
        selectSearchResult,
        saveLocationToProfile
    };
})();

window.TPBLocation = TPBLocation;
