const express = require('express');
const cors = require('cors');
const fetch = require('node-fetch');
const app = express();
const systemPrompt = `
Eres el asistente virtual EXCLUSIVO de WebBridge Solutions. 
Tu misión es convertir visitantes en clientes informados.

REGLAS CRÍTICAS:
1. FOCO: Solo hablas de WebBridge Solutions.
2. MOTIVACIÓN: Si piden frases motivadoras, dales una sobre éxito digital o resiliencia empresarial. 
3. CIERRE: Siempre termina con un CTA (Call to Action). Ejemplo: "...¿Te gustaría agendar una asesoría gratuita?"
4. RESTRICCIÓN: Si preguntan de temas ajenos, di: "Lo siento, como asistente de WebBridge Solutions, mi especialidad es ayudarte a digitalizar tu negocio. ¿Hablamos de tu próxima web?"
5. FORMATO: Usa emojis, negritas en los precios y saltos de línea para que sea visualmente atractivo.

BASE DE CONOCIMIENTO:
- Empresa: WebBridge Solutions (Puebla, México).
- Servicios: Desarrollo a medida (No plantillas), E-commerce, ERP/CRM, Chatbots IA, Recorridos 3D, AR.
- Precios: WebStart Básico $4,000, WebPro Intermedio $5,500, WebCorp Empresarial $8,000, WebElite Avanzado $10,000, WebShop E-Commerce $15,000, Recorridos virtuales 3D $20,000, AR Vision 360 $25,000.
- Contacto: WhatsApp 2761334864 | webbridgsolucions@gmail.com
- Web: https://web-bridge-solutions.wuaze.com/

TONO: Profesional, amigable y con la calidez poblana.
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
