import type { APIRoute } from 'astro';

export const prerender = false;

interface SubscribeRequest {
    firstName: string;
    phone: string;
    postcode: string;
}

export const POST: APIRoute = async ({ request }) => {
    try {
        const data: SubscribeRequest = await request.json();

        // Validate required fields
        if (!data.firstName || !data.phone || !data.postcode) {
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

        const apiKey = import.meta.env.KLAVIYO_API_KEY;
        const listId = import.meta.env.KLAVIYO_LIST_ID;

        if (!apiKey || !listId) {
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

        // Use the Subscribe Profiles endpoint to properly handle SMS consent
        const subscribePayload = {
            data: {
                type: 'profile-subscription-bulk-create-job',
                attributes: {
                    profiles: {
                        data: [
                            {
                                type: 'profile',
                                attributes: {
                                    phone_number: formattedPhone,
                                    first_name: data.firstName,
                                    location: {
                                        zip: data.postcode,
                                        country: 'Australia'
                                    },
                                    properties: {
                                        postcode: data.postcode,
                                        source: 'Ferguson Livestock Website',
                                        signup_date: new Date().toISOString()
                                    }
                                }
                            }
                        ]
                    },
                    historical_import: false
                },
                relationships: {
                    list: {
                        data: {
                            type: 'list',
                            id: listId
                        }
                    }
                }
            }
        };

        const response = await fetch('https://a.klaviyo.com/api/profile-subscription-bulk-create-jobs', {
            method: 'POST',
            headers: {
                'Authorization': `Klaviyo-API-Key ${apiKey}`,
                'Content-Type': 'application/json',
                'revision': '2024-02-15'
            },
            body: JSON.stringify(subscribePayload)
        });

        if (!response.ok) {
            const errorText = await response.text();
            console.error('Klaviyo subscription failed:', errorText);
            throw new Error('Failed to subscribe');
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
                error: 'An error occurred. Please try again.'
            }),
            {
                status: 500,
                headers: { 'Content-Type': 'application/json' }
            }
        );
    }
};

