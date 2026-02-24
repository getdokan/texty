import { Fragment } from 'react';
import { Activity, BarChart3, TrendingUp } from 'lucide-react';

function StatCard({ title, value, subLabel, indicator, loading, progressValue, actionText, actionLink, icon }) {
  const renderIcon = () => {
    if (icon === 'activity') {
      return <Activity size={24} className="texty-stat-card__icon" />;
    } else if (icon === 'chart') {
      return <BarChart3 size={24} className="texty-stat-card__icon" />;
    } else if (icon === 'trending') {
      return <TrendingUp size={24} className="texty-stat-card__icon" />;
    }
    return null;
  };

  return (
    <div className="texty-stat-card">
      <div className="texty-stat-card__header">
        <div className="texty-stat-card__header-top">
          {renderIcon()}
          {indicator && (
            <span className={`texty-stat-badge texty-stat-badge--${indicator}`}>
              {indicator === 'connected' ? 'Active' : 'Inactive'}
            </span>
          )}
        </div>
        <h3 className="texty-stat-card__title">{title}</h3>
      </div>

      <div className="texty-stat-card__body">
        {loading ? (
          <div className="texty-stat-card__loading">
            <div className="texty-spinner"></div>
          </div>
        ) : (
          <Fragment>
            <div className="texty-stat-card__value">{value}</div>

            {progressValue !== undefined && (
              <div className="texty-stat-card__progress">
                <div className="texty-stat-card__progress-bar">
                  <div 
                    className="texty-stat-card__progress-fill" 
                    style={{ width: `${progressValue}%` }}
                  ></div>
                </div>
              </div>
            )}

            {subLabel && (
              <div className="texty-stat-card__sub-label">{subLabel}</div>
            )}

            {actionText && actionLink && (
              <a href={actionLink} className="texty-stat-card__action">
                {actionText} <span>→</span>
              </a>
            )}
          </Fragment>
        )}
      </div>
    </div>
  );
}

export default StatCard;
