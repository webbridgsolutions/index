const express = require('express');
const cors = require('cors');
const fetch = require('node-fetch');
const app = express();
const systemPrompt = `
Eres el asistente virtual EXCLUSIVO de WebBridge Solutions. 
Tu objetivo es ayudar a los clientes con información de la empresa, servicios y paquetes.

REGLAS CRÍTICAS DE COMPORTAMIENTO:
1. SOLO debes hablar sobre WebBridge Solutions y sus servicios.
2. Si el usuario te pregunta sobre otros temas (historia, cocina, programación ajena, chistes, política), responde cortésmente: "Lo siento, como asistente de WebBridge Solutions, solo puedo ayudarte con información sobre nuestros servicios de desarrollo web y sistemas."
3. Usa la siguiente BASE DE CONOCIMIENTO para responder:
   - Servicios: Desarrollo web personalizado, E-commerce, Sistemas ERP/CRM, Chatbots con IA, AR y 3D.
   - Ubicación: Puebla, México.
   - Contacto: WhatsApp 2761334864, Email webbridgsolucions@gmail.com.
   - Precios: WebStart $4,000, WebPro $5,500, WebCorp $8,000, WebElite $10,000, WebShop $15,000.
4. No inventes servicios que no están en esta lista.
5. Mantén un tono profesional, amable y veracruzano/poblano (según tu marca).
`;

app.use(cors({
    origin: '*', // Permite cualquier origen (incluyendo wuaze.com / infinityfree)
    methods: ['POST', 'GET', 'OPTIONS'],
    allowedHeaders: ['Content-Type', 'Authorization', 'X-Requested-With']
}));

app.use(express.json());
app.use(express.urlencoded({ extended: true }));

// Manejar explícitamente el Preflight (petición OPTIONS)
app.options('*', cors());

app.post('/', async (req, res) => {
    const { message, history } = req.body;
    // Ahora usaremos la KEY de Groq
    const apiKey = process.env.GROQ_API_KEY; 

    try {
        let parsedHistory = typeof history === 'string' ? JSON.parse(history) : (history || []);

        const response = await fetch('https://api.groq.com/openai/v1/chat/completions', {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${apiKey}`,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                // Modelo recomendado por su velocidad y capacidad
                model: "llama-3.3-70b-versatile", 
                messages: [
                    { role: "system", content: systemPrompt },
                    ...parsedHistory,
                    { role: "user", content: message }
                ],
                // Desactivamos stream para que InfinityFree no dé error 403/500
                stream: false 
            })
        });

        const data = await response.json();
        res.json({ success: true, message: data.choices[0].message.content });

    } catch (error) {
        res.status(500).json({ success: false, message: error.message });
    }
});

const PORT = process.env.PORT || 10000;
app.listen(PORT, () => console.log(`Servidor Groq listo en puerto ${PORT}`));
