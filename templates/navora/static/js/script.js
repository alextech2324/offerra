(function() {
  const menuButton = document.querySelector('.navbar_menu-button');
  const desktopLinks = document.querySelectorAll('.navbar_menu-links a');
  const desktopButtons = document.querySelectorAll('.navbar_menu-buttons a');

  let mobileMenu = document.getElementById('mobile-menu');
  if (!mobileMenu) {
    mobileMenu = document.createElement('div');
    mobileMenu.id = 'mobile-menu';
    document.body.appendChild(mobileMenu);
  }

  Object.assign(mobileMenu.style, {
    position: 'fixed',
    top: '0',
    right: '0',
    width: '280px',
    height: '100%',
    backgroundColor: '#fff',
    boxShadow: '-2px 0 8px rgba(0,0,0,0.3)',
    flexDirection: 'column',
    display: 'none',
    zIndex: '9999',
    overflowY: 'auto',
    fontFamily: 'Arial, sans-serif',
    paddingTop: '0',
  });

  function fillMenu() {
    mobileMenu.innerHTML = '';

    const closeBtn = document.createElement('div');
    closeBtn.textContent = '✕';
    closeBtn.style.fontSize = '24px';
    closeBtn.style.fontWeight = '700';
    closeBtn.style.padding = '15px';
    closeBtn.style.textAlign = 'right';
    closeBtn.style.cursor = 'pointer';
    closeBtn.addEventListener('click', () => {
      mobileMenu.style.display = 'none';
    });
    mobileMenu.appendChild(closeBtn);

    desktopLinks.forEach(link => {
      const a = link.cloneNode(true);
      a.style.display = 'block';
      a.style.padding = '15px';
      a.style.borderBottom = '1px solid #eee';
      a.style.color = '#333';
      a.style.textDecoration = 'none';
      a.addEventListener('click', () => {
        mobileMenu.style.display = 'none';
      });
      mobileMenu.appendChild(a);
    });

    desktopButtons.forEach(btn => {
      const a = btn.cloneNode(true);
      a.classList.add('button');
      a.style.display = 'block';
      a.style.margin = '10px auto';
      a.style.width = '90%';
      a.style.borderRadius = '5px';
      a.style.backgroundColor = btn.style.backgroundColor || '#ad92de';
      a.style.color = '#fff';
      a.style.textAlign = 'center';
      a.style.textDecoration = 'none';
      a.style.padding = '10px 0';
      a.addEventListener('click', () => {
        mobileMenu.style.display = 'none';
      });
      mobileMenu.appendChild(a);
    });
  }

  if (menuButton) {
    menuButton.addEventListener('click', () => {
      fillMenu();
      mobileMenu.style.display = mobileMenu.style.display === 'flex' ? 'none' : 'flex';
    });
  }
})();
