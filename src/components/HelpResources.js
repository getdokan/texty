import { __ } from '@wordpress/i18n';
import { BookOpen, MessageSquare, Lightbulb } from 'lucide-react';

function HelpResources() {
  const resources = [
    {
      id: 'docs',
      title: __('Documentation', 'texty'),
      description: __('Learn how to set up gateways and create notification workflows', 'texty'),
      url: 'https://texty.dev/docs',
      buttonText: __('Read Docs', 'texty'),
      icon: 'docs',
    },
    {
      id: 'support',
      title: __('Get Support', 'texty'),
      description: __('Need help with Twilio, Vonage, or message delivery? We\'ve got you', 'texty'),
      url: 'https://wordpress.org/support/plugin/texty',
      buttonText: __('Contact Support', 'texty'),
      icon: 'support',
    },
    {
      id: 'issues',
      title: __('Feature Request', 'texty'),
      description: __('Want new notification triggers or gateway integrations? Tell us', 'texty'),
      url: 'https://github.com/weDevsOfficial/texty/issues',
      buttonText: __('Submit Idea', 'texty'),
      icon: 'feature',
    },
  ];

  const renderIcon = (icon) => {
    if (icon === 'docs') {
      return <BookOpen size={32} />;
    } else if (icon === 'support') {
      return <MessageSquare size={32} />;
    } else if (icon === 'feature') {
      return <Lightbulb size={32} />;
    }
    return null;
  };

  return (
    <div className="texty-help-resources">
      {resources.map((resource) => (
        <div
          key={resource.id}
          className="texty-help-resource-card"
        >
          <div className="texty-help-resource-card__icon">
            {renderIcon(resource.icon)}
          </div>
          <h3 className="texty-help-resource-card__title">{resource.title}</h3>
          <p className="texty-help-resource-card__description">
            {resource.description}
          </p>
          <a
            href={resource.url}
            target="_blank"
            rel="noopener noreferrer"
            className="button button-secondary texty-help-resource-btn"
          >
            {resource.buttonText}
          </a>
        </div>
      ))}
    </div>
  );
}

export default HelpResources;
