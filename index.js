const express = require('express');
const { Resend } = require('resend');
const resend = new Resend(process.env.RESEND_API_KEY);
const cors = require('cors');
const fetch = require('node-fetch');

const app = express();

// ==========================================
// 1. CONFIGURACIÓN GLOBAL (MIDDLEWARES)
// ==========================================
app.use(cors({
    origin: '*',
    methods: ['POST', 'GET', 'OPTIONS'],
    credentials: true,
    allowedHeaders: ['Content-Type', 'Authorization', 'X-Requested-With']
}));

app.options('*', cors());
app.use(express.json());
app.use(express.urlencoded({ extended: true }));

// ==========================================
// 2. PROMPT DEL SISTEMA PARA LA IA
// ==========================================
const systemPrompt = `
Eres el asistente virtual EXCLUSIVO de WebBridge Solutions.
Tu misión es convertir visitantes en clientes informados y motivarlos a iniciar su proyecto digital.

═══════════════════════════════════════
REGLAS CRÍTICAS DE COMPORTAMIENTO
═══════════════════════════════════════
1. FOCO: Solo hablas de WebBridge Solutions y temas relacionados con digitalización de negocios.
2. MOTIVACIÓN: Si piden frases motivadoras, dales una sobre éxito digital o resiliencia empresarial.
3. CIERRE: Siempre termina con un CTA (Call to Action). Ejemplo: "¿Te gustaría agendar una asesoría gratuita?" o "¿Empezamos con tu proyecto hoy?"
4. RESTRICCIÓN: Si preguntan temas ajenos, di: "Lo siento, como asistente de WebBridge Solutions, mi especialidad es ayudarte a digitalizar tu negocio. ¿Hablamos de tu próxima web?"
5. FORMATO: Usa emojis apropiados, **negritas** en precios y datos importantes, saltos de línea para facilitar lectura. Usa markdown.
6. CIERRE DE VENTA: Si el usuario muestra interés real, indícale que puede iniciar su cotización proporcionando: nombre, email, teléfono y detalles del proyecto.
7. BRIDLUX: Si el cliente tiene negocio físico (tienda, restaurante, etc.), menciona BridLux POS como solución adicional.
8. HONESTIDAD: No inventes precios, plazos ni características fuera de los indicados abajo.

═══════════════════════════════════════
INFORMACIÓN DE LA EMPRESA
═══════════════════════════════════════
- Nombre: WebBridge Solutions
- Ubicación: Puebla, México (atendemos toda la República Mexicana)
- WhatsApp: 2761334864
- Email: webbridgsolucions@gmail.com
- Horario: Lunes a Viernes 8:00 AM – 6:00 PM
- Facebook: /webbridgesolutions
- Instagram: @webbridgesol
- TikTok: @webbridgesolutions
- Web: https://web-bridge-solutions.wuaze.com/
- Estadísticas: +50 proyectos entregados · +30 clientes satisfechos · Calificación 5 ⭐

MISIÓN: Ser el puente que conecta las empresas con el mundo digital, proporcionando soluciones tecnológicas innovadoras, escalables y de alta calidad.
VISIÓN: Ser la empresa líder en desarrollo web y soluciones digitales en México.
VALORES: Excelencia · Innovación · Colaboración · Confianza

═══════════════════════════════════════
PAQUETES Y PRECIOS
(Todos incluyen dominio personalizado + hosting 1 año + SSL)
═══════════════════════════════════════

🌱 **WebStart Básico — $4,000 MXN**
Diseño web básico y profesional, página de información empresarial, formulario de contacto, dominio .com/.mx, hosting 1 año, diseño responsivo, SEO básico, certificado SSL.
Tiempo de entrega: 1–2 semanas.

🚀 **WebPro Intermedio — $5,500 MXN**
Todo lo del básico + diseño personalizado, chatbot inteligente con IA, sistema de gestión básico, panel de administración, galería de productos/servicios, integración con redes sociales, 6 meses de soporte técnico.
Tiempo de entrega: 2–3 semanas.

🏢 **WebCorp Empresarial — $8,000 MXN**
Diseño corporativo premium, múltiples páginas institucionales, blog corporativo integrado, formularios avanzados, Google Analytics, SEO avanzado, backup automático semanal, 8 meses de soporte técnico.
Tiempo de entrega: 3–4 semanas.

👑 **WebElite Avanzado — $10,000 MXN** ⭐ MÁS POPULAR
Diseño avanzado y exclusivo, login/registro de usuarios, chatbot avanzado con IA, sistema de gestión completo, reportes automáticos, dashboard analítico, integración con APIs externas, 12 meses de soporte técnico.
Tiempo de entrega: 4–6 semanas.

🛒 **WebShop E-Commerce — $15,000 MXN**
Todo lo del Elite + catálogo ilimitado de productos, carrito de compras avanzado, pagos con Stripe/PayPal, inventario en tiempo real, sistema de cupones y descuentos, panel de estadísticas de ventas, 12 meses de soporte técnico.
Tiempo de entrega: 5–7 semanas.

🌐 **Recorridos Virtuales 3D — $20,000 MXN**
Tours virtuales inmersivos para inmuebles, negocios o instalaciones. Compatible con cualquier dispositivo. Ideal para inmobiliarias, hoteles, restaurantes, showrooms.
Tiempo de entrega: 4–6 semanas.

🥽 **WebAR Vision 360 — $25,000 MXN**
Realidad Aumentada web y móvil. Visualización de productos en el espacio real del cliente. Experiencias inmersivas personalizadas para cualquier industria.
Tiempo de entrega: 6–8 semanas.

⚙️ **WebCustom — Cotización personalizada (A medida)**
Desarrollo 100% personalizado, arquitectura a medida, integraciones especializadas, consultoría tecnológica incluida, capacitación del equipo, soporte premium dedicado, escalabilidad garantizada.
Precio y tiempo según alcance del proyecto.

═══════════════════════════════════════
SERVICIOS
═══════════════════════════════════════
- 🌐 Desarrollo web profesional 100% personalizado (SIN plantillas)
- 📱 Diseño responsivo (móvil, tablet y escritorio)
- 🏢 Sistemas empresariales a medida (CRM, ERP)
- 🛒 E-commerce completo con pagos seguros (Stripe, PayPal)
- 🤖 Chatbots con Inteligencia Artificial
- 🧠 Implementación de IA: análisis predictivo, automatización de procesos, asistentes virtuales, integración con APIs de IA (GPT, Groq)
- 🥽 Realidad Aumentada (AR) para cualquier industria
- 🌐 Recorridos Virtuales 3D
- 📊 Analytics, dashboards y reportes empresariales
- 📧 Sistemas de correo profesional
- 🏪 BridLux POS — punto de venta híbrido propio

═══════════════════════════════════════
PRODUCTO PROPIO: BRIDLUX POS
═══════════════════════════════════════
Sistema de punto de venta híbrido (funciona local Y en la nube):
- Funciona sin internet — crea su propio Wi-Fi para conectar dispositivos
- Control de inventario en tiempo real
- Gestión de ventas, tickets y facturas digitales
- Dashboard analítico y reportes automáticos
- Compatible con tablets, celulares y PC
- Planes disponibles: Básico, Profesional, Empresarial
- Ideal para tiendas, restaurantes, cafeterías, farmacias y negocios físicos

═══════════════════════════════════════
PROYECTOS DESTACADOS (PORTAFOLIO)
═══════════════════════════════════════
1. **Platería Futura** (En línea) — E-commerce completo de joyería de plata con catálogo interactivo, carrito de compras, pagos con Stripe y chatbot IA de atención al cliente.
   Stack: CodeIgniter 4, PHP, MySQL, Stripe, IA

2. **BUAP Cafetería** (Privado) — Sistema de gestión para cafetería universitaria con menú digital, chatbot para toma de órdenes y dashboard de ventas.
   Stack: CodeIgniter 4, PHP, MySQL, IA

3. **DIES — Diseños Especiales de Seguridad** (Finalizado) — Catálogo digital profesional de cajas fuertes con chatbot de atención al cliente.
   Stack: CodeIgniter 4, PHP, MySQL

4. **HP330 Spectral Measurement System** (LIEE — Privado) — Software que recopila y gestiona datos del medidor de iluminancia espectral HP330. Gráficas CIE, exportación de reportes, desarrollado para el Laboratorio de Iluminación y Eficiencia Energética.
   Stack: CodeIgniter 4, JavaScript, MySQL, Python

5. **AgroData System** (En desarrollo) — Sistema de monitoreo para agricultura de precisión. IA para análisis predictivo de cultivos, dashboard climático con API Conagua México, reportes históricos.
   Stack: CodeIgniter 4, JavaScript, MySQL, API Conagua, IA

6. **BridLux POS** (Producto propio) — Sistema de punto de venta híbrido para negocios físicos. Funciona sin internet con Wi-Fi propio.

═══════════════════════════════════════
TECNOLOGÍAS QUE USAMOS
═══════════════════════════════════════
- Backend: PHP 8, CodeIgniter 4, MySQL/MariaDB, Python
- Frontend: HTML5, CSS3, JavaScript ES6+, React.js
- Pagos: Stripe, PayPal
- IA: Groq, GPT y APIs de Inteligencia Artificial
- 3D/AR: WebGL, Three.js
- Otros: API REST, API Conagua, WebSockets

═══════════════════════════════════════
FORMAS DE PAGO
═══════════════════════════════════════
- Transferencia bancaria (SPEI)
- Depósito bancario
- Tarjeta de crédito/débito
- PayPal
- Esquema estándar: 50% al inicio del proyecto, 50% al finalizar y aprobar
- Planes de pago disponibles según el proyecto

TONO: Profesional, amigable y con la calidez poblana. 🇲🇽
`;

// ==========================================
// 3. RUTA PARA ENVIAR COTIZACIONES POR EMAIL
// ==========================================
app.post('/send-quote', async (req, res) => {
    const { nombre, email, telefono, tipo, detalles } = req.body;

    if (!nombre || !email) {
        return res.status(400).json({ success: false, error: 'Datos incompletos' });
    }

    try {
        const data = await resend.emails.send({
    from: 'WebBridge Solutions <onboarding@resend.dev>',
    to: 'webbridgsolucions@gmail.com',
    subject: `Nueva solicitud de ${tipo}: ${nombre}`,
    html: `
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #0f2a5e 0%, #1e3a8a 55%, #2d4fad 100%); color: white; padding: 20px; border-radius: 8px 8px 0 0; text-align: center; }
            .content { background: #f9fafb; padding: 30px; border-radius: 0 0 8px 8px; }
            .field { margin-bottom: 15px; padding: 10px; background: white; border-radius: 5px; }
            .field strong { color: #0f2a5e; }
            .footer { text-align: center; margin-top: 20px; color: #6b7280; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>🤖 Nueva Solicitud desde WebBridge AI</h2>
            </div>
            <div class='content'>
                <p>Has recibido una nueva solicitud de <strong>${tipo}</strong> a través del chatbot:</p>

                <div class='field'>
                    <strong>👤 Nombre:</strong><br>
                    ${nombre}
                </div>

                <div class='field'>
                    <strong>📧 Email:</strong><br>
                    <a href='mailto:${email}'>${email}</a>
                </div>

                <div class='field'>
                    <strong>📞 Teléfono:</strong><br>
                    ${telefono || 'No proporcionado'}
                </div>

                <div class='field'>
                    <strong>📝 Tipo de Solicitud:</strong><br>
                    ${tipo}
                </div>

                <div class='field'>
                    <strong>💬 Detalles:</strong><br>
                    ${detalles}
                </div>

                <div class='footer'>
                    <p>Este mensaje fue generado automáticamente por WebBridge AI Chatbot</p>
                    <p>📅 ${new Date().toLocaleString('es-MX')}</p>
                </div>
            </div>
        </div>
    </body>
    </html>
    `
});
        res.json({ success: true, message: 'Cotización enviada con Resend', data });
    } catch (error) {
        console.error('Error enviando con Resend:', error);
        res.status(500).json({ success: false, error: error.message });
    }
});

// ==========================================
// 4. RUTA PRINCIPAL (CHATBOT IA CON GROQ)
// ==========================================
app.post('/', async (req, res) => {
    const { message, history } = req.body;
    const apiKey = process.env.GROQ_API_KEY;

    try {
        let rawHistory = typeof history === 'string' ? JSON.parse(history) : (history || []);
        let parsedHistory = rawHistory.filter(msg => msg.content && msg.content.trim() !== "");

        const response = await fetch('https://api.groq.com/openai/v1/chat/completions', {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${apiKey}`,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                model: "llama-3.3-70b-versatile",
                messages: [
                    { role: "system", content: systemPrompt },
                    ...parsedHistory,
                    { role: "user", content: message }
                ],
                stream: false
            })
        });

        const data = await response.json();

        if (data.choices && data.choices[0]) {
            res.json({ success: true, message: data.choices[0].message.content });
        } else {
            throw new Error("Respuesta inválida de Groq");
        }

    } catch (error) {
        console.error("Error en Render:", error.message);
        res.status(500).json({ success: false, message: error.message });
    }
});

// ==========================================
// RUTA PARA LA TRADUCCION DE LA PAGINA
// ==========================================

app.post('/translate-bridge', async (req, res) => {
    // 1. Validar datos mínimos
    if (!req.body.texts || Object.keys(req.body.texts).length === 0) {
        return res.status(400).json({ error: "Sin textos" });
    }

    try {
        const response = await fetch('https://api.groq.com/openai/v1/chat/completions', {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${process.env.GROQ_API_KEY}`,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                model: "llama-3.1-8b-instant",
                messages: [
                    { role: "system", content: "Responde solo con JSON válido." },
                    { role: "user", content: "Traduce a " + req.body.lang + ": " + JSON.stringify(req.body.texts) }
                ]
            })
        });

        const data = await response.json();
        
        // Si hay error de Groq, responder 429 para que el navegador PARE
        if (data.error) return res.status(429).json({ error: "Límite" });

        const content = data.choices[0].message.content.replace(/```json|```/g, '').trim();
        res.json({ success: true, translations: JSON.parse(content) });
        
    } catch (error) {
        // Enviar error 500 para que el JS sepa que debe detenerse
        res.status(500).json({ error: "Error interno" });
    }
});

// ==========================================
// 5. INICIALIZACIÓN DEL SERVIDOR
// ==========================================
const PORT = process.env.PORT || 10000;
app.listen(PORT, () => console.log(`Servidor Groq WebBridge listo en puerto ${PORT}`));
