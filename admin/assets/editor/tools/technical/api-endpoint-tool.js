/**
 * Task T5: API Endpoint Tool (Skeleton).
 */
(function (global) {
  'use strict';

  class ApiEndpointTool {
    constructor({ data, config, api, readOnly }) {
      this.data = Object.assign({
        method: 'GET',
        endpoint: '/api/v1/resource',
        title: '',
        description: '',
        parameters: [],
        responseBody: ''
      }, data || {});
      this.api = api;
      this.readOnly = readOnly;
    }

    static get toolbox() {
      return {
        icon: '⚡',
        title: 'API Endpoint'
      };
    }

    render() {
      const container = document.createElement('div');
      container.classList.add('kc-block-api-endpoint');
      container.innerHTML = `
        <div class="kc-api-head">
          <select class="kc-api-method-select" ${this.readOnly ? 'disabled' : ''}>
            ${['GET', 'POST', 'PUT', 'DELETE', 'PATCH'].map(m => `<option value="${m}" ${m === this.data.method ? 'selected' : ''}>${m}</option>`).join('')}
          </select>
          <input type="text" class="kc-api-path-input" value="${this.data.endpoint}" ${this.readOnly ? 'readonly' : ''} />
        </div>
        <input type="text" class="kc-api-title-input" placeholder="Endpoint Title" value="${this.data.title}" ${this.readOnly ? 'readonly' : ''} />
      `;
      return container;
    }

    save(blockContent) {
      const methodSelect = blockContent.querySelector('.kc-api-method-select');
      const pathInput = blockContent.querySelector('.kc-api-path-input');
      const titleInput = blockContent.querySelector('.kc-api-title-input');
      return {
        method: methodSelect ? methodSelect.value : 'GET',
        endpoint: pathInput ? pathInput.value : '',
        title: titleInput ? titleInput.value : '',
        description: this.data.description,
        parameters: this.data.parameters,
        responseBody: this.data.responseBody
      };
    }
  }

  global.KcApiEndpointTool = ApiEndpointTool;
})(typeof window !== 'undefined' ? window : this);
