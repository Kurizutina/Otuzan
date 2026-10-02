import React from 'react';
import { useNavigate } from 'react-router-dom';
import Logo from '../components/common/Logo/Logo';
import './NotFound.css';
import '../components/Auth/Auth.css';

const NotFound = () => {
  const navigate = useNavigate();

  return (
    <div className="login-page">
      <div className="auth-simple-card not-found-card">
        <Logo />
        <div className="not-found-code">404</div>
        <div className="auth-simple-header">
          <h1>Page not found</h1>
          <p>The page you&rsquo;re looking for doesn&rsquo;t exist or may have moved.</p>
        </div>
        <button type="button" className="action-btn" onClick={() => navigate('/home')}>
          Back to Home
        </button>
      </div>
    </div>
  );
};

export default NotFound;
