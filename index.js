const express = require('express');
const cors = require('cors');
const fetch = require('node-fetch');
const app = express();

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
                    { role: "system", content: "Eres el asistente de WebBridge Solutions." },
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
