import type { APIRoute } from 'astro';

export const prerender = false;

interface SubscribeRequest {
    firstName: string;
    phone: string;
    postcode: string;
    consent: boolean;
}

export const POST: APIRoute = async ({ request }) => {
    try {
        const data: SubscribeRequest = await request.json();

        // Validate required fields
        if (!data.firstName || !data.phone || !data.postcode || data.consent !== true) {
            return new Response(
                JSON.stringify({
                    success: false,
                    error: 'Missing required fields'
                }),
                {
                    status: 400,
                    headers: { 'Content-Type': 'application/json' }
                }
            );
        }

        // Validate postcode format (Australian 4-digit)
        if (!/^\d{4}$/.test(data.postcode)) {
            return new Response(
                JSON.stringify({
                    success: false,
                    error: 'Invalid postcode format'
                }),
                {
                    status: 400,
                    headers: { 'Content-Type': 'application/json' }
                }
            );
        }

        // Format phone number for Klaviyo (E.164 format)
        let formattedPhone = data.phone.replace(/\s+/g, '').replace(/[^\d+]/g, '');
        if (formattedPhone.startsWith('0')) {
            formattedPhone = '+61' + formattedPhone.slice(1);
        } else if (!formattedPhone.startsWith('+')) {
            formattedPhone = '+61' + formattedPhone;
        }

        const publicApiKey = import.meta.env.KLAVIYO_PUBLIC_API_KEY;
        const privateApiKey = import.meta.env.KLAVIYO_API_KEY;
        const listId = import.meta.env.KLAVIYO_LIST_ID;

        if (!publicApiKey || !listId) {
            console.error('Missing Klaviyo configuration');
            return new Response(
                JSON.stringify({
                    success: false,
                    error: 'Server configuration error'
                }),
                {
                    status: 500,
                    headers: { 'Content-Type': 'application/json' }
                }
            );
        }

        const consentRecordedAt = new Date().toISOString();

        // Step 1: Try client subscription endpoint
        const payload = {
            data: {
                type: 'subscription',
                attributes: {
                    list_id: listId,
                    custom_source: 'Ferguson Livestock Website',
                    phone_number: formattedPhone,
                    properties: {
                        first_name: data.firstName,
                        postcode: data.postcode,
                        signup_date: consentRecordedAt,
                        sms_consent: true,
                        sms_consent_recorded_at: consentRecordedAt,
                        sms_consent_source: 'Ferguson Livestock Website wait list'
                    }
                }
            }
        };

        const response = await fetch(`https://a.klaviyo.com/client/subscriptions/?company_id=${publicApiKey}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'revision': '2023-06-15'
            },
            body: JSON.stringify(payload)
        });

        if (!response.ok) {
            return new Response(
                JSON.stringify({ success: false, error: 'Unable to join the wait list right now' }),
                { status: 502, headers: { 'Content-Type': 'application/json' } }
            );
        }

        // Step 2: Verify profile exists using private API key
        if (privateApiKey) {
            // Wait a moment for Klaviyo to process
            await new Promise(resolve => setTimeout(resolve, 1000));

            // Search for profile by phone number
            const searchUrl = `https://a.klaviyo.com/api/profiles/?filter=equals(phone_number,"${encodeURIComponent(formattedPhone)}")`;
            const searchResponse = await fetch(searchUrl, {
                method: 'GET',
                headers: {
                    'Authorization': `Klaviyo-API-Key ${privateApiKey}`,
                    'Content-Type': 'application/json',
                    'revision': '2024-02-15'
                }
            });

            const searchText = await searchResponse.text();
            if (searchResponse.ok) {
                try {
                    const searchData = JSON.parse(searchText);
                    if (searchData.data && searchData.data.length > 0) {
                        // Profile exists - update it with first_name and add to list
                        const profileId = searchData.data[0].id;

                        // Update profile with first_name
                        const updatePayload = {
                            data: {
                                type: 'profile',
                                id: profileId,
                                attributes: {
                                    first_name: data.firstName,
                                    properties: {
                                        postcode: data.postcode,
                                        source: 'Ferguson Livestock Website',
                                        sms_consent: true,
                                        sms_consent_recorded_at: consentRecordedAt,
                                        sms_consent_source: 'Ferguson Livestock Website wait list'
                                    }
                                }
                            }
                        };

                        const updateResponse = await fetch(`https://a.klaviyo.com/api/profiles/${profileId}/`, {
                            method: 'PATCH',
                            headers: {
                                'Authorization': `Klaviyo-API-Key ${privateApiKey}`,
                                'Content-Type': 'application/json',
                                'revision': '2024-02-15'
                            },
                            body: JSON.stringify(updatePayload)
                        });

                        // Add to list
                        const listPayload = {
                            data: [
                                {
                                    type: 'profile',
                                    id: profileId
                                }
                            ]
                        };

                        const listResponse = await fetch(`https://a.klaviyo.com/api/lists/${listId}/relationships/profiles/`, {
                            method: 'POST',
                            headers: {
                                'Authorization': `Klaviyo-API-Key ${privateApiKey}`,
                                'Content-Type': 'application/json',
                                'revision': '2024-02-15'
                            },
                            body: JSON.stringify(listPayload)
                        });

                    } else {
                        // Profile doesn't exist - create it
                        const createPayload = {
                            data: {
                                type: 'profile',
                                attributes: {
                                    phone_number: formattedPhone,
                                    first_name: data.firstName,
                                    properties: {
                                        postcode: data.postcode,
                                        source: 'Ferguson Livestock Website',
                                        signup_date: consentRecordedAt,
                                        sms_consent: true,
                                        sms_consent_recorded_at: consentRecordedAt,
                                        sms_consent_source: 'Ferguson Livestock Website wait list'
                                    }
                                }
                            }
                        };

                        const createResponse = await fetch('https://a.klaviyo.com/api/profiles/', {
                            method: 'POST',
                            headers: {
                                'Authorization': `Klaviyo-API-Key ${privateApiKey}`,
                                'Content-Type': 'application/json',
                                'revision': '2024-02-15'
                            },
                            body: JSON.stringify(createPayload)
                        });

                        const createText = await createResponse.text();
                        if (createResponse.ok || createResponse.status === 201) {
                            const createData = JSON.parse(createText);
                            const newProfileId = createData.data.id;

                            // Add to list
                            const listPayload = {
                                data: [
                                    {
                                        type: 'profile',
                                        id: newProfileId
                                    }
                                ]
                            };

                            const listResponse = await fetch(`https://a.klaviyo.com/api/lists/${listId}/relationships/profiles/`, {
                                method: 'POST',
                                headers: {
                                    'Authorization': `Klaviyo-API-Key ${privateApiKey}`,
                                    'Content-Type': 'application/json',
                                    'revision': '2024-02-15'
                                },
                                body: JSON.stringify(listPayload)
                            });

                        }
                    }
                } catch (e) {
                    console.error('Error parsing search response:', e);
                }
            }
        }

        return new Response(
            JSON.stringify({
                success: true,
                message: 'Successfully subscribed to the wait list!'
            }),
            {
                status: 200,
                headers: { 'Content-Type': 'application/json' }
            }
        );

    } catch (error) {
        console.error('Subscribe error:', error);
        return new Response(
            JSON.stringify({
                success: false,
                error: 'Unable to join the wait list right now'
            }),
            {
                status: 500,
                headers: { 'Content-Type': 'application/json' }
            }
        );
    }
};
