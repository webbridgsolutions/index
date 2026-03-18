<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;

class ChatbotControllerGroq extends ResourceController
{
    protected $format = 'json';
    
    private $renderUrl = 'https://webbridge-ai.onrender.com';

    public function message()
    {
        // HABILITAR HEADERS CORS
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
        header("Access-Control-Allow-Origin: $origin");
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Access-Control-Allow-Credentials: true');
        
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            exit;
        }

        try {
            // Obtener datos del post
            $userMessage = $this->request->getPost('message');
            $historyRaw = $this->request->getPost('history');
            $conversationHistory = json_decode($historyRaw ?? '[]', true);

            if (empty($userMessage)) {
                return $this->respond(['success' => false, 'error' => 'Mensaje vacío'], 400);
            }

            // Intentar llamar a Render
            $response = $this->callRenderProxy($userMessage, $conversationHistory);

            if ($response['success']) {
                return $this->respond([
                    'success' => true,
                    'message' => $response['message'],
                    'mode' => 'ai_render',
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
            } else {
                // Fallback local
                log_message('warning', 'Render falló, usando fallback local: ' . ($response['error'] ?? 'Unknown'));
                
                return $this->respond([
                    'success' => true,
                    'message' => $this->getSmartFallbackResponse($userMessage),
                    'mode' => 'fallback_local',
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
            }

        } catch (\Exception $e) {
            log_message('error', 'Error en controlador: ' . $e->getMessage());
            return $this->respond([
                'success' => true, 
                'message' => $this->getSmartFallbackResponse($userMessage ?? ''),
                'mode' => 'fallback_exception',
                'timestamp' => date('Y-m-d H:i:s')
            ], 200);
        }
    }

    /**
     * Enviar formulario de cotización o cita
     */
  
public function sendQuoteRequest()
{
    $nombre   = $this->request->getPost('nombre');
    $email    = $this->request->getPost('email');
    $telefono = $this->request->getPost('telefono');
    $tipo     = $this->request->getPost('tipo') ?? 'cotización';
    $detalles = $this->request->getPost('detalles');

    if ($nombre && $email) {
        $this->sendEmail($nombre, $email, $telefono, $tipo, $detalles);
        
        // 🔥 Guardamos el mensaje en la sesión (Flash Data)
        session()->setFlashdata('status_chatbot', 'success');
        
        return redirect()->to(base_url('#contacto'));
    }

    session()->setFlashdata('status_chatbot', 'error');
    return redirect()->to(base_url());
}
    
    /**
     * Enviar email con la solicitud
     */
    private function sendEmail($nombre, $email, $telefono, $tipo, $detalles)
    {
        try {
            $emailService = \Config\Services::email();
            
            $emailService->setFrom('webbridgsolucions@gmail.com', 'WebBridge AI Chatbot');
            $emailService->setTo('webbridgsolucions@gmail.com');
            $emailService->setSubject('Nueva Solicitud de ' . ucfirst($tipo) . ' desde Chatbot');

            $message = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); color: white; padding: 20px; border-radius: 8px 8px 0 0; }
                    .content { background: #f9fafb; padding: 30px; border-radius: 0 0 8px 8px; }
                    .field { margin-bottom: 15px; padding: 10px; background: white; border-radius: 5px; }
                    .field strong { color: #1e3a8a; }
                    .footer { text-align: center; margin-top: 20px; color: #6b7280; font-size: 12px; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>🤖 Nueva Solicitud desde WebBridge AI</h2>
                    </div>
                    <div class='content'>
                        <p>Has recibido una nueva solicitud de <strong>" . ucfirst($tipo) . "</strong> a través del chatbot:</p>
                        
                        <div class='field'>
                            <strong>👤 Nombre:</strong><br>
                            " . htmlspecialchars($nombre) . "
                        </div>
                        
                        <div class='field'>
                            <strong>📧 Email:</strong><br>
                            <a href='mailto:" . htmlspecialchars($email) . "'>" . htmlspecialchars($email) . "</a>
                        </div>
                        
                        <div class='field'>
                            <strong>📞 Teléfono:</strong><br>
                            " . (!empty($telefono) ? htmlspecialchars($telefono) : 'No proporcionado') . "
                        </div>
                        
                        <div class='field'>
                            <strong>📝 Tipo de Solicitud:</strong><br>
                            " . ucfirst($tipo) . "
                        </div>
                        
                        <div class='field'>
                            <strong>💬 Detalles:</strong><br>
                            " . nl2br(htmlspecialchars($detalles)) . "
                        </div>
                        
                        <div class='footer'>
                            <p>Este mensaje fue generado automáticamente por WebBridge AI Chatbot</p>
                            <p>📅 " . date('d/m/Y H:i:s') . "</p>
                        </div>
                    </div>
                </div>
            </body>
            </html>
            ";

            $emailService->setMessage($message);
            
            if ($emailService->send()) {
                log_message('info', 'Email enviado correctamente a webbridgsolucions@gmail.com');
                echo 'no error';
                //return true;
            } else {
                log_message('error', 'Error al enviar email: ' . $emailService->printDebugger(['headers']));
                echo 'error';
                //return false;
            }

        } catch (\Exception $e) {
            log_message('error', 'Excepción al enviar email: ' . $e->getMessage());
            echo 'error';
            //return false;
        }
    }

    private function callRenderProxy(string $userMessage, array $conversationHistory): array
    {
        try {
            $data = [
                'message' => $userMessage,
                'history' => $conversationHistory
            ];

            $ch = curl_init($this->renderUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json'
                ],
                CURLOPT_TIMEOUT => 25,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false
            ]);

            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($result === false) {
                return ['success' => false, 'error' => $error];
            }

            $response = json_decode($result, true);
            
            if ($httpCode === 200 && isset($response['success']) && $response['success'] === true) {
                return [
                    'success' => true,
                    'message' => $response['message']
                ];
            }

            return ['success' => false, 'error' => 'Status Code: ' . $httpCode];

        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function getSmartFallbackResponse(string $message): string
    {
        $lowerMessage = strtolower($message);
        $lowerMessage = $this->removeAccents($lowerMessage);

        // ── PROYECTOS ──────────────────────────────────────────────────────
        if (preg_match('/(proyecto|portafolio|ejemplo|trabajo|plateria|cafeteria|dies|liee|hp330|agrodata|bridlux)/i', $lowerMessage)) {
            return "💼 **Proyectos Destacados de WebBridge Solutions:**\n\n" .
                   "**1. Platería Futura** 🏆 _(En línea)_\n" .
                   "• E-commerce completo de joyería de plata\n" .
                   "• Catálogo interactivo con carrito de compras\n" .
                   "• Pagos seguros integrados con Stripe\n" .
                   "• Chatbot de atención al cliente con IA\n" .
                   "• Tecnologías: CodeIgniter 4, PHP, MySQL, Stripe, IA\n\n" .
                   "**2. BUAP Cafetería** ☕ _(Privado)_\n" .
                   "• Sistema de gestión para cafetería universitaria\n" .
                   "• Menú digital interactivo con carrito de pedidos\n" .
                   "• Chatbot automatizado para toma de órdenes\n" .
                   "• Dashboard de administración y reportes de ventas\n" .
                   "• Tecnologías: CodeIgniter 4, PHP, MySQL, IA\n\n" .
                   "**3. DIES — Diseños Especiales de Seguridad** 🔒 _(Finalizado)_\n" .
                   "• Catálogo digital profesional de cajas fuertes\n" .
                   "• Chatbot de atención y presentación de productos\n" .
                   "• Diseño responsivo y corporativo\n" .
                   "• Tecnologías: CodeIgniter 4, PHP, MySQL\n\n" .
                   "**4. HP330 Spectral Measurement System** ⚡ _(LIEE — Privado)_\n" .
                   "• Software para medidor de iluminancia espectral HP330\n" .
                   "• Gestión de conjuntos, gráficas CIE y exportación de reportes\n" .
                   "• Desarrollado para el Lab. de Iluminación y Eficiencia Energética\n" .
                   "• Tecnologías: CodeIgniter 4, JavaScript, MySQL, Python\n\n" .
                   "**5. AgroData System** 🌱 _(En desarrollo)_\n" .
                   "• Sistema de monitoreo para agricultura de precisión\n" .
                   "• IA para análisis predictivo de cultivos\n" .
                   "• Dashboard climático con API Conagua México\n" .
                   "• Tecnologías: CodeIgniter 4, JavaScript, MySQL, API Conagua, IA\n\n" .
                   "**6. BridLux POS** 🏪 _(Producto propio)_\n" .
                   "• Sistema de punto de venta híbrido (local + nube)\n" .
                   "• Funciona con Wi-Fi propio sin necesidad de internet\n" .
                   "• Control de inventario, ventas y reportes en tiempo real\n\n" .
                   "¿Te gustaría algo similar para tu negocio? 🚀";
        }

        // ── BRIDLUX POS ────────────────────────────────────────────────────
        if (preg_match('/(bridlux|punto de venta|pos|inventario|venta|tienda fisica)/i', $lowerMessage)) {
            return "🏪 **BridLux — Sistema Punto de Venta**\n\n" .
                   "Nuestro producto propio de POS híbrido:\n\n" .
                   "• Funciona **local y en la nube** — opera aunque no haya internet\n" .
                   "• Crea su propio Wi-Fi para conectar dispositivos\n" .
                   "• Control de inventario en tiempo real\n" .
                   "• Gestión de ventas, tickets y facturas\n" .
                   "• Reportes automáticos de ventas y dashboard analítico\n" .
                   "• Compatible con tablets, celulares y PC\n\n" .
                   "**Planes disponibles:** Básico · Profesional · Empresarial\n\n" .
                   "¿Quieres una demo o cotización? 📞 2761334864";
        }

        // ── IA / INTELIGENCIA ARTIFICIAL ──────────────────────────────────
        if (preg_match('/(inteligencia artificial|ia|chatbot|automatizar|machine learning|ai)/i', $lowerMessage)) {
            return "🤖 **Implementación de Inteligencia Artificial**\n\n" .
                   "Integramos IA en tus sistemas y páginas web:\n\n" .
                   "• **Chatbots inteligentes** — atención 24/7 automatizada\n" .
                   "• **Análisis predictivo** — anticipa tendencias de tu negocio\n" .
                   "• **Automatización de procesos** — elimina tareas repetitivas\n" .
                   "• **Asistentes virtuales** con voz y texto\n" .
                   "• **Integración con APIs de IA** (GPT, Groq, etc.)\n\n" .
                   "Disponible en los paquetes **WebElite**, **WebShop** y **WebCustom**.\n\n" .
                   "¿Te interesa automatizar algún proceso de tu negocio? 💡";
        }

        // ── REALIDAD AUMENTADA / 3D ────────────────────────────────────────
        if (preg_match('/(realidad aumentada|ar|3d|recorrido virtual|360|virtual|augmented)/i', $lowerMessage)) {
            return "🥽 **Realidad Aumentada y Recorridos 3D**\n\n" .
                   "**Realidad Aumentada (AR):**\n" .
                   "• Visualización de productos en el espacio real\n" .
                   "• Aplicaciones AR para móvil y web\n" .
                   "• **AR Vision 360** — \$25,000 MXN\n\n" .
                   "**Recorridos Virtuales 3D:**\n" .
                   "• Tours virtuales de inmuebles, negocios o instalaciones\n" .
                   "• Compatible con cualquier dispositivo\n" .
                   "• **Recorridos 3D** — \$20,000 MXN\n\n" .
                   "Ideal para inmobiliarias, restaurantes, hoteles y tiendas. ¿Cotizamos? 📞";
        }

        // ── COTIZACIÓN / CITA ──────────────────────────────────────────────
        if (preg_match('/(cotiz|presupuesto|agendar|cita|reunion|precio proyecto|quiero|necesito)/i', $lowerMessage)) {
            return "📋 **Solicitar Cotización**\n\n" .
                   "¡Con gusto te ayudamos!\n\n" .
                   "Para preparar tu cotización personalizada necesito:\n\n" .
                   "**👤 Tu nombre completo**\n" .
                   "**📧 Tu correo electrónico**\n" .
                   "**📞 Tu teléfono** (opcional)\n" .
                   "**💬 Descripción de tu proyecto**\n\n" .
                   "O contáctanos directamente:\n" .
                   "📱 **WhatsApp:** 2761334864\n" .
                   "📧 **Email:** webbridgsolucions@gmail.com\n\n" .
                   "Te respondemos en **menos de 24 horas**. 🚀";
        }

        // ── SOBRE LA EMPRESA ───────────────────────────────────────────────
        if (preg_match('/(que es|quienes son|sobre|acerca|empresa|webbridge|quien|historia|mision|vision)/i', $lowerMessage)) {
            return "🏢 **¿Quiénes somos?**\n\n" .
                   "**WebBridge Solutions** somos una empresa mexicana de desarrollo web profesional con sede en Puebla, México.\n\n" .
                   "**Nuestra misión:** Ser el puente que conecta tu empresa con el mundo digital.\n\n" .
                   "**Nos especializamos en:**\n" .
                   "• Desarrollo web 100% personalizado (sin plantillas)\n" .
                   "• Sistemas de gestión empresarial\n" .
                   "• E-commerce completo\n" .
                   "• Inteligencia Artificial aplicada\n" .
                   "• BridLux POS — punto de venta propio\n" .
                   "• Realidad aumentada y recorridos 3D\n\n" .
                   "**Valores:** Excelencia · Innovación · Colaboración · Confianza\n\n" .
                   "**+50 proyectos** · **+30 clientes** · Calificación **5 ⭐**\n\n" .
                   "¿Quieres conocer nuestros paquetes y servicios?";
        }

        // ── SOPORTE / MANTENIMIENTO ────────────────────────────────────────
        if (preg_match('/(soporte|mantenimiento|garantia|actualizacion|despues|post)/i', $lowerMessage)) {
            return "🛠️ **Soporte Técnico Incluido**\n\n" .
                   "• **WebStart / WebPro** — 6 meses de soporte\n" .
                   "• **WebCorp** — 8 meses de soporte\n" .
                   "• **WebElite / WebShop** — 12 meses de soporte\n" .
                   "• **WebCustom** — Soporte premium dedicado\n\n" .
                   "También ofrecemos **planes de mantenimiento extendido**.\n\n" .
                   "📞 2761334864 · 📧 webbridgsolucions@gmail.com";
        }

        // ── PAGOS ──────────────────────────────────────────────────────────
        if (preg_match('/(pago|pagar|factura|transferencia|deposito|credito|mensualidad|abono)/i', $lowerMessage)) {
            return "💳 **Formas de Pago**\n\n" .
                   "• Transferencia bancaria (SPEI)\n" .
                   "• Depósito bancario\n" .
                   "• Tarjeta de crédito/débito\n" .
                   "• PayPal\n\n" .
                   "**Esquema habitual:**\n" .
                   "• 50% al inicio del proyecto\n" .
                   "• 50% al finalizar y aprobar\n\n" .
                   "También manejamos **planes de pago** según el proyecto.\n\n" .
                   "📞 2761334864";
        }

        // ── UBICACIÓN ─────────────────────────────────────────────────────
        if (preg_match('/(donde|ubicacion|direccion|oficina|ciudad|estado)/i', $lowerMessage)) {
            return "📍 **Ubicación**\n\n" .
                   "**Puebla, México**\n" .
                   "Atendemos clientes de toda la República de forma remota.\n\n" .
                   "📞 WhatsApp: 2761334864\n" .
                   "📧 webbridgsolucions@gmail.com\n" .
                   "⏰ Lun–Vie: 8:00 AM – 6:00 PM\n\n" .
                   "🌐 Instagram: @webbridgesol · TikTok: @webbridgesolutions";
        }

        // ── TIEMPOS DE ENTREGA ─────────────────────────────────────────────
        if (preg_match('/(cuanto tiempo|plazo|entrega|duracion|cuando esta|semana)/i', $lowerMessage)) {
            return "⏱️ **Tiempos de Desarrollo**\n\n" .
                   "• **WebStart** — 1–2 semanas\n" .
                   "• **WebPro** — 2–3 semanas\n" .
                   "• **WebCorp** — 3–4 semanas\n" .
                   "• **WebElite** — 4–6 semanas\n" .
                   "• **WebShop** — 5–7 semanas\n" .
                   "• **Recorridos 3D** — 4–6 semanas\n" .
                   "• **AR Vision 360** — 6–8 semanas\n" .
                   "• **WebCustom** — Según alcance\n\n" .
                   "¿Tienes una fecha límite? Te ayudamos a planificar. 📅";
        }

        // ── PAQUETES / PRECIOS ─────────────────────────────────────────────
        if (preg_match('/(paquete|precio|costo|cuanto cuesta|plan|tarifa|econom|barat|car)/i', $lowerMessage)) {
            return "📦 **Paquetes WebBridge Solutions**\n\n" .
                   "**1. WebStart Básico** — \$4,000 MXN\n" .
                   "Diseño web, dominio, hosting 1 año, SSL, responsivo, SEO básico\n\n" .
                   "**2. WebPro Intermedio** — \$5,500 MXN\n" .
                   "Todo lo anterior + chatbot IA, panel de admin, galería, redes, 6 meses soporte\n\n" .
                   "**3. WebCorp Empresarial** — \$8,000 MXN\n" .
                   "Diseño corporativo, blog, Analytics, SEO avanzado, backup, 8 meses soporte\n\n" .
                   "**4. WebElite Avanzado** ⭐ _(Más popular)_ — \$10,000 MXN\n" .
                   "Login/registro, chatbot IA avanzado, sistema gestión, dashboard, APIs, 12 meses soporte\n\n" .
                   "**5. WebShop E-Commerce** — \$15,000 MXN\n" .
                   "Tienda completa, Stripe/PayPal, inventario, cupones, estadísticas, 12 meses soporte\n\n" .
                   "**Extras:** Recorridos 3D \$20,000 · AR Vision 360 \$25,000 · WebCustom a medida\n\n" .
                   "Todos incluyen **dominio y hosting por 1 año**. 🎁\n\n" .
                   "¿Cuál se adapta mejor a tu negocio?";
        }

        // ── TECNOLOGÍAS ────────────────────────────────────────────────────
        if (preg_match('/(tecnologia|lenguaje|programacion|php|javascript|react|mysql|framework|backend|frontend)/i', $lowerMessage)) {
            return "💻 **Tecnologías que usamos**\n\n" .
                   "**Backend:** PHP 8, CodeIgniter 4, MySQL, Python\n" .
                   "**Frontend:** HTML5, CSS3, JavaScript ES6+, React.js\n" .
                   "**Pagos:** Stripe, PayPal\n" .
                   "**IA:** Groq, GPT y APIs de IA\n" .
                   "**3D/AR:** WebGL, Three.js\n" .
                   "**Otros:** API REST, API Conagua, WebSockets\n\n" .
                   "¿Tienes alguna tecnología específica en mente?";
        }

        // ── SERVICIOS ──────────────────────────────────────────────────────
        if (preg_match('/(servicio|que hacen|ofrecen|hacen|desarrollan)/i', $lowerMessage)) {
            return "🚀 **Servicios de WebBridge Solutions**\n\n" .
                   "🌐 Desarrollo web profesional personalizado\n" .
                   "📱 Diseño responsivo (móvil, tablet, escritorio)\n" .
                   "🏢 Sistemas empresariales (CRM, ERP, POS)\n" .
                   "🛒 E-commerce con pagos integrados\n" .
                   "🤖 Chatbots con Inteligencia Artificial\n" .
                   "🥽 Realidad Aumentada (AR)\n" .
                   "🌐 Recorridos Virtuales 3D\n" .
                   "📊 Analytics y reportes\n" .
                   "📧 Sistemas de correo profesional\n" .
                   "🏪 BridLux POS — punto de venta propio\n\n" .
                   "¿Qué servicio necesita tu negocio? 💡";
        }

        // ── CONTACTO ───────────────────────────────────────────────────────
        if (preg_match('/(contacto|telefono|whatsapp|email|correo|llamar|escribir|comunicar)/i', $lowerMessage)) {
            return "📞 **Contáctanos**\n\n" .
                   "📱 **WhatsApp:** 2761334864\n" .
                   "📧 **Email:** webbridgsolucions@gmail.com\n" .
                   "⏰ **Horario:** Lun–Vie 8:00 AM – 6:00 PM\n\n" .
                   "**Redes sociales:**\n" .
                   "• Facebook: /webbridgesolutions\n" .
                   "• Instagram: @webbridgesol\n" .
                   "• TikTok: @webbridgesolutions\n\n" .
                   "¡Respondemos en menos de 24 horas! 🚀";
        }

        // ── SALUDOS ────────────────────────────────────────────────────────
        if (preg_match('/^(hola|hello|hi|buenos|hey|buenas|saludos|que tal|como estas)/i', $lowerMessage)) {
            return "¡Hola! 👋 Soy el asistente de **WebBridge Solutions**.\n\n" .
                   "Estoy aquí para ayudarte con todo lo que necesites:\n\n" .
                   "📦 **Paquetes** — desde \$4,000 MXN\n" .
                   "🚀 **Servicios** — web, IA, AR, POS y más\n" .
                   "💼 **Proyectos** — conoce nuestro portafolio\n" .
                   "🏪 **BridLux POS** — nuestro punto de venta\n" .
                   "⏱️ **Tiempos** — plazos de entrega\n" .
                   "📞 **Contacto** — 2761334864\n\n" .
                   "**¿En qué puedo ayudarte hoy?** 😊";
        }

        // ── DESPEDIDAS ─────────────────────────────────────────────────────
        if (preg_match('/(gracias|bye|adios|hasta luego|chao|nos vemos)/i', $lowerMessage)) {
            return "¡Fue un placer ayudarte! 😊\n\n" .
                   "Recuerda que estamos aquí cuando lo necesites:\n\n" .
                   "📞 2761334864\n" .
                   "📧 webbridgsolucions@gmail.com\n\n" .
                   "¡Mucho éxito en tu proyecto! 🚀✨";
        }

        // ── GENÉRICA ───────────────────────────────────────────────────────
        return "¡Hola! 👋 Soy el asistente de **WebBridge Solutions**.\n\n" .
               "Conectamos tu negocio con el mundo digital. Te puedo ayudar con:\n\n" .
               "🏢 **Sobre nosotros** — quiénes somos\n" .
               "📦 **Paquetes** — desde \$4,000 MXN\n" .
               "🚀 **Servicios** — web, IA, AR, E-commerce\n" .
               "🏪 **BridLux POS** — punto de venta propio\n" .
               "💼 **Proyectos** — portafolio de trabajos\n" .
               "⏱️ **Tiempos** — plazos de entrega\n" .
               "💳 **Pagos** — formas de pago disponibles\n" .
               "🛠️ **Soporte** — mantenimiento incluido\n" .
               "📞 **Contacto** — 2761334864\n\n" .
               "**¿Qué te gustaría saber?**";
    }
    private function removeAccents(string $string): string
    {
        return str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ñ', 'Á', 'É', 'Í', 'Ó', 'Ú', 'Ñ'],
            ['a', 'e', 'i', 'o', 'u', 'n', 'A', 'E', 'I', 'O', 'U', 'N'],
            $string
        );
    }

    private function getWebBridgeKnowledge(): string
    {
        return "Eres un asistente profesional, amigable y experto de WebBridge Solutions, empresa mexicana de desarrollo web con sede en Puebla, México.

═══════════════════════════════════════
INFORMACIÓN DE LA EMPRESA
═══════════════════════════════════════
- Nombre: WebBridge Solutions
- Ubicación: Puebla, México (atendemos toda la República)
- Teléfono/WhatsApp: 2761334864
- Email: webbridgsolucions@gmail.com
- Horario: Lun–Vie 8:00 AM – 6:00 PM
- Facebook: /webbridgesolutions
- Instagram: @webbridgesol
- TikTok: @webbridgesolutions
- Estadísticas: +50 proyectos entregados, +30 clientes, calificación 5★

MISIÓN: Ser el puente que conecta las empresas con el mundo digital, proporcionando soluciones tecnológicas innovadoras, escalables y de alta calidad.
VISIÓN: Ser la empresa líder en desarrollo web y soluciones digitales en México.
VALORES: Excelencia · Innovación · Colaboración · Confianza

═══════════════════════════════════════
PAQUETES Y PRECIOS (todos incluyen dominio + hosting 1 año)
═══════════════════════════════════════
1. WebStart Básico — \$4,000 MXN
   - Diseño web básico y profesional
   - Página de información empresarial
   - Formulario de contacto por email
   - Dominio personalizado (.com/.mx)
   - Hosting básico por 1 año
   - Diseño responsivo
   - Optimización SEO básica
   - Certificado SSL

2. WebPro Intermedio — \$5,500 MXN
   - Todo lo del paquete básico
   - Diseño web personalizado
   - Chatbot inteligente integrado con IA
   - Sistema de gestión básico
   - Panel de administración
   - Galería de productos/servicios
   - Integración con redes sociales
   - Soporte técnico 6 meses

3. WebCorp Empresarial — \$8,000 MXN
   - Diseño web corporativo premium
   - Múltiples páginas institucionales
   - Blog corporativo integrado
   - Formularios avanzados
   - Google Analytics integrado
   - SEO avanzado
   - Backup automático semanal
   - Soporte técnico 8 meses

4. WebElite Avanzado — \$10,000 MXN (MÁS POPULAR ⭐)
   - Diseño web avanzado y exclusivo
   - Login/registro de usuarios
   - Chatbot avanzado con IA
   - Sistema de gestión completo
   - Reportes automáticos
   - Dashboard analítico
   - Integración con APIs externas
   - Soporte técnico 12 meses

5. WebShop E-Commerce — \$15,000 MXN
   - Todo lo del paquete Elite
   - Catálogo ilimitado de productos
   - Carrito de compras avanzado
   - Pagos con Stripe / PayPal
   - Inventario en tiempo real
   - Sistema de cupones y descuentos
   - Panel de estadísticas de ventas
   - Soporte técnico 12 meses

6. Recorridos Virtuales 3D — \$20,000 MXN
   - Tours virtuales inmersivos
   - Compatible con cualquier dispositivo
   - Ideal para inmobiliarias, hoteles, restaurantes

7. WebAR Vision 360 — \$25,000 MXN
   - Realidad Aumentada web y móvil
   - Visualización de productos en espacio real
   - Experiencias inmersivas para clientes

8. WebCustom — A medida (cotización personalizada)
   - Desarrollo 100% personalizado
   - Arquitectura a medida
   - Integraciones especializadas
   - Consultoría tecnológica incluida
   - Capacitación del equipo
   - Soporte premium dedicado

═══════════════════════════════════════
SERVICIOS
═══════════════════════════════════════
- Desarrollo web profesional 100% personalizado (SIN plantillas)
- Diseño responsivo (móvil, tablet, escritorio)
- Sistemas de gestión empresarial (CRM, ERP)
- E-commerce completo con pagos seguros
- Chatbots con Inteligencia Artificial
- Implementación de IA: análisis predictivo, automatización, asistentes virtuales
- Realidad Aumentada (AR) para cualquier industria
- Recorridos Virtuales 3D
- Analytics y reportes empresariales
- Sistemas de correo profesional
- BridLux POS — punto de venta propio híbrido

═══════════════════════════════════════
PRODUCTO PROPIO: BRIDLUX POS
═══════════════════════════════════════
Sistema de punto de venta híbrido (local + nube):
- Funciona sin internet, crea su propio Wi-Fi
- Control de inventario en tiempo real
- Gestión de ventas, tickets y facturas
- Dashboard analítico y reportes automáticos
- Compatible con tablets, celulares y PC
- Planes: Básico, Profesional, Empresarial
- Ideal para tiendas, restaurantes, negocios físicos

═══════════════════════════════════════
PROYECTOS DESTACADOS
═══════════════════════════════════════
1. Platería Futura (En línea) — E-commerce joyería con Stripe, chatbot IA. Stack: CI4, PHP, MySQL, Stripe
2. BUAP Cafetería (Privado) — Sistema gestión universitaria, chatbot pedidos, dashboard. Stack: CI4, PHP, MySQL, IA
3. DIES Seguridad (Finalizado) — Catálogo digital cajas fuertes, chatbot. Stack: CI4, PHP, MySQL
4. HP330 Spectral System (LIEE — Privado) — Software medidor iluminancia, gráficas CIE, Python. Stack: CI4, JS, MySQL, Python
5. AgroData System (En desarrollo) — Agricultura de precisión, IA predictiva, API Conagua. Stack: CI4, JS, MySQL, IA
6. BridLux POS (Producto propio) — POS híbrido local/nube para negocios físicos

═══════════════════════════════════════
TECNOLOGÍAS
═══════════════════════════════════════
Backend: PHP 8, CodeIgniter 4, MySQL/MariaDB, Python
Frontend: HTML5, CSS3, JavaScript ES6+, React.js
Pagos: Stripe, PayPal
IA: Groq, GPT, APIs de IA
3D/AR: WebGL, Three.js
Otros: API REST, API Conagua, WebSockets

═══════════════════════════════════════
TIEMPOS DE DESARROLLO
═══════════════════════════════════════
- WebStart: 1–2 semanas
- WebPro: 2–3 semanas
- WebCorp: 3–4 semanas
- WebElite: 4–6 semanas
- WebShop: 5–7 semanas
- Recorridos 3D: 4–6 semanas
- AR Vision 360: 6–8 semanas
- WebCustom: Según alcance

═══════════════════════════════════════
FORMAS DE PAGO
═══════════════════════════════════════
- Transferencia bancaria (SPEI)
- Depósito bancario
- Tarjeta de crédito/débito
- PayPal
- Esquema: 50% al inicio, 50% al finalizar
- También disponibles planes de pago personalizados

═══════════════════════════════════════
INSTRUCCIONES DE COMPORTAMIENTO
═══════════════════════════════════════
- Sé amigable, profesional y entusiasta
- Responde siempre en español usando emojis apropiados
- Usa formato Markdown para claridad (negritas, listas)
- Respuestas concisas pero completas — nunca demasiado largas
- Siempre ofrece valor y guía al usuario hacia una solución
- Para cotizaciones, solicita: nombre, email, teléfono y detalles del proyecto
- Menciona BridLux cuando el cliente tenga negocio físico o necesite POS
- Si preguntan por tecnología específica, sé honesto sobre lo que usamos
- Siempre invita a contactar al 2761334864 o webbridgsolucions@gmail.com
- No inventes precios ni plazos fuera de los indicados arriba";
    }
}
