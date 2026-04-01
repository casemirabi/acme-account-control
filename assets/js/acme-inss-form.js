// Função responsável por recarregar a página sem perder a posição de rolagem.
// Antes de dar reload, ela salva o scroll atual no sessionStorage.
function acmeInssSoftReload() {
  try {
    const currentScrollY = window.scrollY || 0;
    sessionStorage.setItem('acme_inss_scroll_y', String(currentScrollY));
  } catch (error) { }

  window.location.reload();
}

// Quando o DOM terminar de carregar,
// tenta restaurar a posição do scroll salva anteriormente.
document.addEventListener('DOMContentLoaded', () => {
  try {
    const savedScrollY = Number(sessionStorage.getItem('acme_inss_scroll_y') || '0');

    if (savedScrollY > 0) {
      window.scrollTo(0, savedScrollY);
    }

    sessionStorage.removeItem('acme_inss_scroll_y');
  } catch (error) { }
});

// Função centralizadora da chave usada para salvar o último resultado da consulta.
function acmeInssStorageKey() {
  return 'acme_inss_last_result_v1';
}

// Salva no sessionStorage o último resultado da consulta.
function acmeInssSaveLastResult(payload) {
  try {
    sessionStorage.setItem(acmeInssStorageKey(), JSON.stringify(payload));
  } catch (error) { }
}

// Carrega do sessionStorage o último resultado salvo.
function acmeInssLoadLastResult() {
  try {
    const rawValue = sessionStorage.getItem(acmeInssStorageKey());

    if (!rawValue) {
      return null;
    }

    return JSON.parse(rawValue);
  } catch (error) {
    return null;
  }
}

// Remove do sessionStorage o último resultado salvo.
function acmeInssClearLastResult() {
  try {
    sessionStorage.removeItem(acmeInssStorageKey());
  } catch (error) { }
}

// Extrai a melhor mensagem de erro possível de vários formatos de payload.
function acmeInssPickErrorMessage(payload) {
  if (!payload) return 'Falha ao consultar INSS.';

  if (typeof payload === 'string') return payload;

  if (typeof payload === 'object') {
    if (typeof payload.message === 'string' && payload.message.trim()) {
      return payload.message;
    }

    if (typeof payload.error === 'string' && payload.error.trim()) {
      return payload.error;
    }

    if (payload.error && typeof payload.error === 'object') {
      if (typeof payload.error.message === 'string' && payload.error.message.trim()) {
        return payload.error.message;
      }

      if (typeof payload.error.code === 'string' && payload.error.code.trim()) {
        return payload.error.code;
      }
    }
  }

  try {
    return JSON.stringify(payload);
  } catch (error) {
    return 'Falha ao consultar INSS.';
  }
}

// Renderer reutilizável para formulário e modal do painel.
// container: elemento que receberá o HTML do resultado.
function renderSuccess(container, data, requestId = '') {
  if (!container) return;

  const responseData = data?.response_data || data || {};
  const dados = responseData?.dados || {};

  const nome = dados?.nome || '-';
  const beneficio = dados?.beneficio || '-';
  const situacao = dados?.situacao || '-';
  const especie = dados?.especie?.descricao || '-';

  const elegivel = dados?.elegivelEmprestimo ? 'Sim' : 'Não';

  const bloqueioBruto =
    dados?.bloqueioEmprestimo ??
    dados?.bloqueioEmprestismo ??
    false;

  const bloqueio = bloqueioBruto ? 'Sim' : 'Não';

  const banco = dados?.banco?.descricao || '-';
  const agencia = dados?.agencia || '-';
  const conta = dados?.conta || '-';

  const valorBase = dados?.valorBase ?? '-';
  const margem = dados?.margemConsignavel ?? '-';

  const pdfButtonHtml =
    requestId && window.ACME_INSS?.pdfUrl
      ? `
        <a
          href="${window.ACME_INSS.pdfUrl}&request_id=${encodeURIComponent(requestId)}"
          class="acme-btn"
          target="_blank"
          rel="noopener noreferrer"
        >
          Baixar PDF
        </a>
      `
      : '';

  let contratosHtml = '';

  const contratosEmprestimo = Array.isArray(responseData?.contratosEmprestimo)
    ? responseData.contratosEmprestimo
    : (Array.isArray(dados?.contratosEmprestimo) ? dados.contratosEmprestimo : []);

  const contratosRMC = Array.isArray(responseData?.contratosRMC)
    ? responseData.contratosRMC
    : (Array.isArray(dados?.contratosRMC) ? dados.contratosRMC : []);

  const contratosRCC = Array.isArray(responseData?.contratosRCC)
    ? responseData.contratosRCC
    : (Array.isArray(dados?.contratosRCC) ? dados.contratosRCC : []);

  contratosEmprestimo.forEach((contratoData) => {
    contratosHtml += `
      <div class="acme-field acme-inss-contrato">
        <div class="acme-label">${contratoData?.tipoEmprestimo || 'Empréstimo consignado'}</div>
        <div class="acme-value">${contratoData?.contrato || '-'}</div>
        <div class="acme-muted">
          Banco: ${contratoData?.banco?.descricao || '-'}<br>
          Parcelas: ${contratoData?.quantidadeParcelas ?? '-'}<br>
          Parcela: ${contratoData?.valorParcela ?? '-'}<br>
          Situação: ${contratoData?.situacao || '-'}
        </div>
      </div>
    `;
  });

  contratosRMC.forEach((contratoData) => {
    contratosHtml += `
      <div class="acme-field acme-inss-contrato">
        <div class="acme-label">${contratoData?.tipoEmprestimo || 'RMC'}</div>
        <div class="acme-value">${contratoData?.contrato || '-'}</div>
        <div class="acme-muted">
          Banco: ${contratoData?.banco?.descricao || '-'}<br>
          Limite: ${contratoData?.valorLimiteCartao ?? '-'}<br>
          Reservado: ${contratoData?.valorReservado ?? '-'}<br>
          Situação: ${contratoData?.situacao || '-'}
        </div>
      </div>
    `;
  });

  contratosRCC.forEach((contratoData) => {
    contratosHtml += `
      <div class="acme-field acme-inss-contrato">
        <div class="acme-label">${contratoData?.tipoEmprestimo || 'RCC'}</div>
        <div class="acme-value">${contratoData?.contrato || '-'}</div>
        <div class="acme-muted">
          Banco: ${contratoData?.banco?.descricao || '-'}<br>
          Limite: ${contratoData?.valorLimiteCartao ?? '-'}<br>
          Reservado: ${contratoData?.valorReservado ?? '-'}<br>
          Situação: ${contratoData?.situacao || '-'}
        </div>
      </div>
    `;
  });

  container.innerHTML = `
    <div class="acme-card">
      <div class="acme-card-h">
        <div class="acme-title">Resultado da Consulta INSS</div>
        <div class="acme-actions">
          <span class="acme-badge ${situacao === 'ATIVO' ? 'acme-badge-ok' : 'acme-badge-bad'}">
            ${situacao || '-'}
          </span>
          <span class="acme-badge ${elegivel === 'Sim' ? 'acme-badge-ok' : 'acme-badge-bad'}">
            Elegível: ${elegivel}
          </span>
          ${pdfButtonHtml}
        </div>
      </div>

      <div class="acme-card-b">
        <div class="acme-grid">
          <div class="acme-field">
            <div class="acme-label">Nome</div>
            <div class="acme-value">${nome}</div>
          </div>

          <div class="acme-field">
            <div class="acme-label">Benefício</div>
            <div class="acme-value">${beneficio}</div>
          </div>

          <div class="acme-field">
            <div class="acme-label">Espécie</div>
            <div class="acme-value">${especie}</div>
          </div>

          <div class="acme-field">
            <div class="acme-label">Bloqueio</div>
            <div class="acme-value">${bloqueio}</div>
          </div>

          <div class="acme-field">
            <div class="acme-label">Banco</div>
            <div class="acme-value">${banco}</div>
          </div>

          <div class="acme-field">
            <div class="acme-label">Agência / Conta</div>
            <div class="acme-value">${agencia} / ${conta}</div>
          </div>

          <div class="acme-field">
            <div class="acme-label">Valor Base</div>
            <div class="acme-value">${valorBase}</div>
          </div>

          <div class="acme-field">
            <div class="acme-label">Margem</div>
            <div class="acme-value">${margem}</div>
          </div>
        </div>

        <div class="acme-inss-contracts">
          <div class="acme-title" style="margin-top:14px;">Contratos</div>
          ${contratosHtml || '<div class="acme-msg">Nenhum contrato encontrado.</div>'}
        </div>
      </div>
    </div>
  `;
}

if (!window.ACME_INSS_UI || typeof window.ACME_INSS_UI !== 'object') {
  window.ACME_INSS_UI = {};
}
window.ACME_INSS_UI.renderSuccess = renderSuccess;

async function fetchInssStatusByRequestId(requestId) {
  const url = new URL(ACME_INSS.restStatus);
  url.searchParams.set('request_id', String(requestId || ''));

  const resp = await fetch(url.toString(), {
    method: 'GET',
    credentials: 'same-origin',
    headers: {
      'X-WP-Nonce': ACME_INSS.restNonce,
    },
  });

  const text = await resp.text();
  let json = null;

  try {
    json = JSON.parse(text);
  } catch (e) { }

  if (!resp.ok || !json || json.success !== true) {
    throw new Error(
      (json && (json.message || (json.error && json.error.message))) ||
      'Não foi possível carregar a consulta INSS.'
    );
  }

  return json.data || {};
}

function extractInssResponseDataFromStatus(st) {
  if (!st) return null;

  if (st.data && st.data.response_data && typeof st.data.response_data === 'object') {
    return st.data.response_data;
  }

  if (st.response_data && typeof st.response_data === 'object') {
    return st.response_data;
  }

  if (st.data && st.data.dados !== undefined) {
    return { dados: st.data.dados };
  }

  if (st.dados !== undefined) {
    return { dados: st.dados };
  }

  return null;
}

function initInssPanelViewer() {
  const modal = document.getElementById('acme-inss-panel-modal');
  if (!modal) return;

  const resultBox = modal.querySelector('.acme-inss-panel-modal-result');
  if (!resultBox) return;

  const closeModal = () => {
    modal.setAttribute('hidden', 'hidden');
    document.documentElement.classList.remove('acme-inss-modal-open');
    resultBox.innerHTML = '';
  };

  const openModal = () => {
    modal.removeAttribute('hidden');
    document.documentElement.classList.add('acme-inss-modal-open');
  };

  document.addEventListener('click', async (event) => {
    const closeTrigger = event.target.closest('[data-acme-inss-close="1"]');
    if (closeTrigger) {
      closeModal();
      return;
    }

    const viewBtn = event.target.closest('.acme-inss-view-btn');
    if (!viewBtn) return;

    event.preventDefault();

    const requestId = String(viewBtn.getAttribute('data-request-id') || '').trim();
    if (!requestId) return;

    openModal();
    resultBox.innerHTML = '<div class="acme-msg">Carregando consulta...</div>';

    try {
      const data = await fetchInssStatusByRequestId(requestId);

      if (data.status === 'failed') {
        const errorMessage =
          data && data.error && data.error.message
            ? data.error.message
            : 'Falha ao carregar a consulta.';
        resultBox.innerHTML = '<div class="acme-msg acme-msg-err">' + errorMessage + '</div>';
        return;
      }

      if (data.status !== 'completed') {
        resultBox.innerHTML = '<div class="acme-msg">Consulta ainda não foi concluída.</div>';
        return;
      }

      const responseData = extractInssResponseDataFromStatus({ data });

      if (!responseData) {
        resultBox.innerHTML = '<div class="acme-msg acme-msg-err">Consulta concluída, mas sem dados para exibir.</div>';
        return;
      }

      window.ACME_INSS_UI.renderSuccess(
        resultBox,
        { response_data: responseData },
        requestId
      );

    } catch (error) {
      resultBox.innerHTML = '<div class="acme-msg acme-msg-err">' + (error.message || 'Erro ao carregar consulta.') + '</div>';
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !modal.hasAttribute('hidden')) {
      closeModal();
    }
  });
}

// IIFE do formulário INSS.
// Fica separado do viewer do painel para não matar o JS quando a página tiver só o painel.
(function () {
  const form = document.querySelector('.acme-inss-form');

  if (!form) return;

  const input = form.querySelector('.acme-inss-beneficio');
  const button = form.querySelector('.acme-inss-submit');
  const result = form.querySelector('.acme-inss-result');

  if (!input || !button || !result) return;

  let acmeInssDotsTimer = null;

  function sleep(milliseconds) {
    return new Promise((resolve) => setTimeout(resolve, milliseconds));
  }

  function onlyDigits(value) {
    return String(value || '').replace(/\D+/g, '');
  }

  function renderMessage(message, kind = '') {
    let cssClass = 'acme-inss-msg';

    if (kind === 'err') cssClass += ' acme-msg acme-msg-err';
    if (kind === 'ok') cssClass += ' acme-msg acme-msg-ok';

    result.innerHTML = '<div class="' + cssClass + '">' + message + '</div>';
  }

  function startMovingDots(baseText, kind = '') {
    stopMovingDots();

    let currentStep = 0;

    acmeInssDotsTimer = setInterval(() => {
      currentStep = (currentStep + 1) % 4;
      const dots = '.'.repeat(currentStep);
      renderMessage(baseText + dots, kind);
    }, 350);
  }

  function stopMovingDots() {
    if (acmeInssDotsTimer) {
      clearInterval(acmeInssDotsTimer);
      acmeInssDotsTimer = null;
    }
  }

  async function startConsulta(beneficio) {
    const response = await fetch(ACME_INSS.restStart, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': ACME_INSS.restNonce
      },
      body: JSON.stringify({
        beneficio: String(beneficio || '')
      })
    });

    const responseText = await response.text();
    let payload = null;

    try {
      payload = JSON.parse(responseText);
    } catch (error) {
      payload = null;
    }

    if (!response.ok || !payload) {
      throw new Error(
        acmeInssPickErrorMessage(payload) ||
        responseText ||
        `HTTP ${response.status}`
      );
    }

    if (payload.success !== true) {
      throw new Error(acmeInssPickErrorMessage(payload));
    }

    return payload;
  }

  async function getStatus(requestId) {
    const statusUrl = ACME_INSS.restStatus + '?request_id=' + encodeURIComponent(requestId);

    const response = await fetch(statusUrl, {
      method: 'GET',
      credentials: 'same-origin',
      headers: {
        'X-WP-Nonce': ACME_INSS.restNonce
      }
    });

    const payload = await response.json().catch(() => null);

    if (!payload || payload.success !== true) {
      throw new Error('Erro ao consultar status do INSS.');
    }

    return payload;
  }

  function extractStatus(payload) {
    if (!payload) return '';
    if (payload.status) return String(payload.status);
    if (payload.data?.status) return String(payload.data.status);
    if (payload.data?.request?.status) return String(payload.data.request.status);
    if (payload.row?.status) return String(payload.row.status);
    return '';
  }

  function extractRequestId(payload) {
    return (
      payload?.request_id ||
      payload?.data?.request_id ||
      ''
    );
  }

  function extractResponseData(payload) {
    return (
      payload?.response_data ||
      payload?.data?.response_data ||
      null
    );
  }

  async function run() {
    const beneficio = onlyDigits(input.value);

    if (!beneficio) {
      renderMessage('Informe o número do benefício.', 'err');
      return;
    }

    button.disabled = true;
    startMovingDots('Consultando INSS');

    try {
      const started = await startConsulta(beneficio);

      stopMovingDots();

      const requestId = extractRequestId(started);
      const startedStatus = extractStatus(started);

      if (startedStatus === 'completed' || started?.data?.status === 'completed') {
        const responseData = extractResponseData(started);

        if (responseData) {
          renderSuccess(result, { response_data: responseData }, requestId);

          acmeInssSaveLastResult({
            requestId,
            status: 'completed',
            responseData
          });
        } else {
          const errorMessage =
            started?.data?.message ||
            started?.message ||
            'Consulta finalizada, porém sem dados para exibir.';

          renderMessage(errorMessage, 'err');

          acmeInssSaveLastResult({
            requestId,
            status: 'failed',
            error: errorMessage
          });
        }

        acmeInssSoftReload();
        return;
      }

      if (startedStatus === 'failed') {
        const errorMessage = acmeInssPickErrorMessage(started?.data?.error || started?.error || started);

        renderMessage(errorMessage, 'err');

        acmeInssSaveLastResult({
          requestId,
          status: 'failed',
          error: errorMessage
        });

        acmeInssSoftReload();
        return;
      }

      const timeoutMilliseconds = 180000;
      const intervalMilliseconds = 3000;
      const startedAt = Date.now();

      startMovingDots('Consulta iniciada. Processando');

      while (true) {
        await sleep(intervalMilliseconds);

        const statusPayload = await getStatus(requestId);
        const currentStatus = extractStatus(statusPayload);

        if (!currentStatus || currentStatus === 'pending' || currentStatus === 'processing') {
          if (Date.now() - startedAt > timeoutMilliseconds) {
            stopMovingDots();

            renderMessage(
              'Ainda processando...<br>O tempo estimado foi excedido.<br>Atualize a página para consultar novamente.'
            );
            return;
          }

          continue;
        }

        if (currentStatus === 'failed') {
          stopMovingDots();

          const errorMessage = acmeInssPickErrorMessage(
            statusPayload?.data?.error || statusPayload?.error || statusPayload
          );

          acmeInssSaveLastResult({
            requestId,
            status: 'failed',
            error: errorMessage
          });

          acmeInssSoftReload();
          return;
        }

        if (currentStatus === 'completed') {
          stopMovingDots();

          const responseData = extractResponseData(statusPayload);

          if (responseData) {
            renderSuccess(result, { response_data: responseData }, requestId);

            acmeInssSaveLastResult({
              requestId,
              status: 'completed',
              responseData
            });
          } else {
            const errorMessage =
              statusPayload?.data?.message ||
              'Consulta finalizada, porém sem dados para exibir.';

            renderMessage(errorMessage, 'err');

            acmeInssSaveLastResult({
              requestId,
              status: 'failed',
              error: errorMessage
            });
          }

          acmeInssSoftReload();
          return;
        }

        stopMovingDots();
        renderMessage('Status inesperado: ' + currentStatus, 'err');
        return;
      }
    } catch (error) {
      stopMovingDots();

      renderMessage(
        error && error.message ? error.message : 'Erro na requisição.',
        'err'
      );
    } finally {
      stopMovingDots();
      button.disabled = false;
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    const savedResult = acmeInssLoadLastResult();

    if (!savedResult) return;

    if (savedResult.status === 'completed') {
      if (savedResult.responseData) {
        renderSuccess(result, { response_data: savedResult.responseData }, savedResult.requestId || '');
      } else {
        renderMessage('Consulta finalizada, porém sem dados para exibir.', 'err');
      }

      acmeInssClearLastResult();
      return;
    }

    if (savedResult.status === 'failed') {
      renderMessage(savedResult.error || 'Falha na consulta.', 'err');
      acmeInssClearLastResult();
    }
  });

  button.addEventListener('click', function (event) {
    event.preventDefault();
    run();
  });
})();

document.addEventListener('DOMContentLoaded', function () {
  initInssPanelViewer();
});