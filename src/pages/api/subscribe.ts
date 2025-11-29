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
                        signup_date: new Date().toISOString()
                    }
                }
            }
        };

        console.log('Step 1 - Client subscription payload:', JSON.stringify(payload, null, 2));

        const response = await fetch(`https://a.klaviyo.com/client/subscriptions/?company_id=${publicApiKey}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'revision': '2023-06-15'
            },
            body: JSON.stringify(payload)
        });

        const responseText = await response.text();
        console.log('Step 1 - Client subscription response:', response.status, responseText);

        // Step 2: Verify profile exists using private API key
        if (privateApiKey) {
            console.log('Step 2 - Checking if profile exists...');

            // Wait a moment for Klaviyo to process
            await new Promise(resolve => setTimeout(resolve, 1000));

            // Search for profile by phone number
            const searchUrl = `https://a.klaviyo.com/api/profiles/?filter=equals(phone_number,"${encodeURIComponent(formattedPhone)}")`;
            console.log('Step 2 - Search URL:', searchUrl);

            const searchResponse = await fetch(searchUrl, {
                method: 'GET',
                headers: {
                    'Authorization': `Klaviyo-API-Key ${privateApiKey}`,
                    'Content-Type': 'application/json',
                    'revision': '2024-02-15'
                }
            });

            const searchText = await searchResponse.text();
            console.log('Step 2 - Profile search response:', searchResponse.status, searchText);

            if (searchResponse.ok) {
                try {
                    const searchData = JSON.parse(searchText);
                    if (searchData.data && searchData.data.length > 0) {
                        console.log('Step 2 - Profile FOUND:', searchData.data[0].id);

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
                                        source: 'Ferguson Livestock Website'
                                    }
                                }
                            }
                        };

                        console.log('Step 3 - Updating profile:', JSON.stringify(updatePayload, null, 2));

                        const updateResponse = await fetch(`https://a.klaviyo.com/api/profiles/${profileId}/`, {
                            method: 'PATCH',
                            headers: {
                                'Authorization': `Klaviyo-API-Key ${privateApiKey}`,
                                'Content-Type': 'application/json',
                                'revision': '2024-02-15'
                            },
                            body: JSON.stringify(updatePayload)
                        });

                        console.log('Step 3 - Profile update response:', updateResponse.status);

                        // Add to list
                        const listPayload = {
                            data: [
                                {
                                    type: 'profile',
                                    id: profileId
                                }
                            ]
                        };

                        console.log('Step 4 - Adding to list:', listId);

                        const listResponse = await fetch(`https://a.klaviyo.com/api/lists/${listId}/relationships/profiles/`, {
                            method: 'POST',
                            headers: {
                                'Authorization': `Klaviyo-API-Key ${privateApiKey}`,
                                'Content-Type': 'application/json',
                                'revision': '2024-02-15'
                            },
                            body: JSON.stringify(listPayload)
                        });

                        console.log('Step 4 - Add to list response:', listResponse.status);

                    } else {
                        console.log('Step 2 - Profile NOT found, creating new one...');

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
                                        signup_date: new Date().toISOString()
                                    }
                                }
                            }
                        };

                        console.log('Step 3 - Creating profile:', JSON.stringify(createPayload, null, 2));

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
                        console.log('Step 3 - Create profile response:', createResponse.status, createText);

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

                            console.log('Step 4 - Adding new profile to list:', listId);

                            const listResponse = await fetch(`https://a.klaviyo.com/api/lists/${listId}/relationships/profiles/`, {
                                method: 'POST',
                                headers: {
                                    'Authorization': `Klaviyo-API-Key ${privateApiKey}`,
                                    'Content-Type': 'application/json',
                                    'revision': '2024-02-15'
                                },
                                body: JSON.stringify(listPayload)
                            });

                            console.log('Step 4 - Add to list response:', listResponse.status);
                        }
                    }
                } catch (e) {
                    console.error('Error parsing search response:', e);
                }
            }
        } else {
            console.log('No private API key - skipping verification');
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
                error: error instanceof Error ? error.message : 'An error occurred'
            }),
            {
                status: 500,
                headers: { 'Content-Type': 'application/json' }
            }
        );
    }
};
