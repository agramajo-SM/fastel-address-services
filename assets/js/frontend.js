window.initAvalaunchMap = async function () {
    const { PlaceAutocompleteElement } = await google.maps.importLibrary("places");

    const input = document.getElementById('avalaunch-address-input');
    if (!input) return;

    try {
        // Create the new PlaceAutocompleteElement
        const autocomplete = new PlaceAutocompleteElement();

        // Attach listener BEFORE adding to DOM
        autocomplete.addEventListener('gmp-places-select', async (event) => {
            const place = event.place;

            if (!place) {
                return;
            }

            try {
                // Fetch fields needed for address components and location
                await place.fetchFields({
                    fields: ['displayName', 'formattedAddress', 'addressComponents', 'location'],
                });

                // Extract address components
                var addressData = {
                    formatted_address: place.formattedAddress,
                    postal_code: '',
                    locality: '',
                    country: ''
                };

                if (place.addressComponents) {
                    for (const component of place.addressComponents) {
                        const addressType = component.types[0];
                        if (addressType === 'postal_code') {
                            addressData.postal_code = component.longText;
                        }
                        if (addressType === 'locality') {
                            addressData.locality = component.longText;
                        }
                        if (addressType === 'country') {
                            addressData.country = component.longText;
                        }
                    }
                }

                const $ = jQuery;
                $('#avalaunch-services-results').hide().html('<p>Searching for services...</p>').fadeIn();

                $.ajax({
                    url: avalaunch_vars.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'avalaunch_get_services',
                        nonce: avalaunch_vars.nonce,
                        address_data: addressData
                    },
                    success: function (response) {
                        var $results = $('#avalaunch-services-results');
                        $results.empty();

                        if (response.success) {
                            var services = response.data;
                            if (services.length > 0) {
                                var html = '<h3>Available Services:</h3><ul>';
                                $.each(services, function (index, service) {
                                    html += '<li>' + service + '</li>';
                                });
                                html += '</ul>';
                                $results.html(html);
                            } else {
                                $results.html('<p>No services found for this location.</p>');
                            }
                        } else {
                            $results.html('<p class="error">Error: ' + response.data + '</p>');
                        }
                    },
                    error: function (xhr, status, error) {
                        $('#avalaunch-services-results').html('<p class="error">An unexpected error occurred.</p>');
                    }
                });
            } catch (err) {
                // Silent catch
            }
        });

        autocomplete.id = 'avalaunch-address-input'; // Reuse ID for styling, though CSS adjustments likely needed
        autocomplete.placeholder = 'Start typing your address...';

        // Replace the old input with the new element
        input.replaceWith(autocomplete);

    } catch (e) {
        // Silent catch
    }
};
